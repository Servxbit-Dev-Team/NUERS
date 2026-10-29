<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\{ApiAccess, BirCompliance, MerchantInvoiceSettings, SafeImage};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt, DB};

class MerchantBrandController extends Controller
{
    public function update(Request $request)
    {
        return $this->save($request, false);
    }

    public function invoiceLogo(Request $request)
    {
        return $this->save($request, true);
    }

    private function save(Request $request, bool $invoice)
    {
        $user = app(ApiAccess::class)->require($request, ['merchant']);
        $merchant = DB::table('merchants')->where('merchant_account_id', $user->id)->orderBy('id')->first();
        abort_unless($merchant, 403, 'Only the business owner can manage its images.');
        abort_if(in_array($merchant->status, ['suspended', 'inactive']), 403, 'This business is suspended.');
        app(MerchantInvoiceSettings::class)->requireOwnerMfa($user);
        $validated = $request->validate([
            'expected_logo' => 'present|nullable|string|max:2048',
            ...($request->isMethod('DELETE') ? [] : ['logo' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048']),
        ]);
        $column = $invoice ? 'invoice_logo_url' : 'logo_url';
        $folder = $invoice ? 'invoice-logos' : 'business-logos';
        $directory = public_path('uploads/'.$folder);
        $name = null;
        $url = null;
        if (! $request->isMethod('DELETE')) {
            $name = app(SafeImage::class)->store($request->file('logo'), $directory);
            $url = '/public/uploads/'.$folder.'/'.$name;
        }
        try {
            return DB::transaction(function () use ($user, $merchant, $validated, $column, $invoice, $url) {
                $fresh = DB::table('merchants')->where('id', $merchant->id)->lockForUpdate()->first();
                abort_unless($fresh && (string) $fresh->merchant_account_id === (string) $user->id && ! in_array($fresh->status, ['suspended', 'inactive']), 403, 'Business ownership or access changed.');
                abort_unless((string) ($fresh->$column ?? '') === (string) ($validated['expected_logo'] ?? ''), 409, 'This image changed in another window. Refresh before replacing it.');
                DB::table('merchants')->where('id', $fresh->id)->update([$column => $url, 'updated_at' => now()]);
                if (! $invoice) {
                    // Supersede only old image-only requests. Registered-identity reviews remain pending.
                    $pending = DB::table('business_registry_requests')->where('merchant_id', $fresh->id)->where('purpose', 'profile_change')->whereNull('completed_at')->lockForUpdate()->get();
                    foreach ($pending as $row) {
                        $payload = json_decode(Crypt::decryptString($row->payload), true, flags: JSON_THROW_ON_ERROR);
                        if (array_keys($payload['changes'] ?? []) !== ['logo_url']) continue;
                        $payload['status'] = 'withdrawn';
                        $payload['review_reason'] = 'Replaced by an immediate owner profile image update.';
                        DB::table('business_registry_requests')->where('id', $row->id)->update(['payload' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)), 'completed_at' => now(), 'revoked_at' => now(), 'updated_at' => now()]);
                        app(BirCompliance::class)->audit('business.profile.change_withdrawn', $fresh->id, (string) $user->id, ['request_id' => $row->id, 'reason' => $payload['review_reason']]);
                    }
                }
                app(BirCompliance::class)->audit($invoice ? 'invoice.logo.updated' : 'business.profile.image_updated', $fresh->id, (string) $user->id, ['previous_logo' => $fresh->$column ?? null, 'logo' => $url]);
                return response()->json(['logo' => $url, 'merchant_id' => $fresh->id, 'pending_approval' => false])->header('Cache-Control', 'private, no-store');
            });
        } catch (\Throwable $error) {
            if ($name && is_file($directory.'/'.$name)) unlink($directory.'/'.$name);
            throw $error;
        }
    }
}
