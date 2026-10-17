<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class BusinessAccountController extends Controller
{
    public function current(Request $request): JsonResponse
    {
        $user = $this->userFromRequest($request);

        abort_unless($user, 401, 'Authenticated Business Account session is required.');

        $merchant = $this->merchantForUser($user);

        abort_unless($merchant, 404, 'No Business Account registry record is linked to this login.');

        return response()->json([
            'data' => $this->merchantPayload($merchant, $request),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $this->userFromRequest($request);

        abort_unless($user, 401, 'Authenticated Business Account session is required.');

        $merchant = $this->merchantForUser($user);

        abort_unless($merchant, 404, 'No Business Account registry record is linked to this login.');

        $validated = $request->validate([
            'business_name' => ['required', 'string', 'max:255'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'tin' => ['nullable', 'string', 'max:80'],
            'sector' => ['nullable', 'string', 'max:120'],
            'business_type' => ['nullable', 'string', 'max:120'],
            'bir_registration_date' => ['nullable', 'date'],
            'vat_registered' => ['nullable', 'boolean'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:80'],
            'address' => ['nullable', 'string'],
            'country' => ['nullable', 'string', 'max:80'],
            'country_code' => ['nullable', 'string', 'max:8'],
            'city' => ['nullable', 'string', 'max:255'],
            'city_code' => ['nullable', 'string', 'max:80'],
            'barangay' => ['nullable', 'string', 'max:255'],
            'barangay_code' => ['nullable', 'string', 'max:80'],
            'zip_code' => ['nullable', 'string', 'max:40'],
            'region' => ['nullable', 'string', 'max:120'],
            'rdo_code' => ['nullable', 'string', 'max:20'],
            'rdo_name' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'employee_count' => ['nullable', 'integer', 'min:0'],
            'annual_revenue' => ['nullable', 'numeric', 'min:0'],
            'pos_system' => ['nullable', 'string', 'max:255'],
            'branch_count' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        foreach(['business_name','tin','business_type','bir_registration_date','vat_registered','address','country','country_code','city','city_code','barangay','barangay_code','zip_code','region','rdo_code','rdo_name','branch_count'] as $field) {
            if(array_key_exists($field,$validated) && (string)($validated[$field] ?? '')!==(string)($merchant->$field ?? '')) abort(409,'Registered identity, address, tax and branch changes require the reviewed Business Registry workflow.');
        }
        $updates = [
            ...$validated,
            'compliance_score' => $this->computedComplianceScore((object) [...(array) $merchant, ...$validated]),
            'updated_at' => now(),
        ];

        DB::table('merchants')->where('id', $merchant->id)->update($updates);

        if (array_key_exists('email',$validated) && ($validated['email']??null) !== ($merchant->email??null)) app(\App\Services\PlatformNotifications::class)->user($user->id,'security.business_contact.changed',(string)\Illuminate\Support\Str::uuid(),'Business profile contact changed','The contact information for your NUERS business profile was updated. Review Business Profile if this was unexpected.','security');
        $this->audit('business_account.profile.updated', $merchant->id, $user, $request, [
            'updated_fields' => array_keys($validated),
            'notes' => $validated['notes'] ?? null,
        ]);

        $fresh = DB::table('merchants')->where('id', $merchant->id)->first();

        return response()->json([
            'data' => $this->merchantPayload($fresh, $request),
        ]);
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $user = $this->userFromRequest($request);

        abort_unless($user, 401, 'Authenticated Business Account session is required.');
        abort_unless(Schema::hasColumn('merchants', 'logo_url'), 500, 'Business Account logo storage is not migrated yet.');

        $merchant = $this->merchantForUser($user);

        abort_unless($merchant, 404, 'No Business Account registry record is linked to this login.');

        $request->validate([
            'logo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:2048'],
        ]);

        $file = $request->file('logo');
        $directory = public_path('uploads/business-logos');
        File::ensureDirectoryExists($directory);

        $fileName = app(\App\Services\SafeImage::class)->store($file,$directory);

        $logoUrl = '/uploads/business-logos/'.$fileName;

        DB::table('merchants')->where('id', $merchant->id)->update([
            'logo_url' => $logoUrl,
            'updated_at' => now(),
        ]);

        $this->audit('business_account.logo.updated', $merchant->id, $user, $request, [
            'logo_url' => $logoUrl,
            'original_name' => $file->getClientOriginalName(),
        ]);

        $fresh = DB::table('merchants')->where('id', $merchant->id)->first();

        return response()->json([
            'data' => $this->merchantPayload($fresh, $request),
        ]);
    }

    public function lookupByTin(Request $request): JsonResponse
    {
        abort_unless($this->userFromRequest($request), 401, 'Authenticated NUERS session is required.');

        $tin = $this->normalizeTin((string) $request->query('tin', ''));

        abort_if(strlen($tin) < 9, 422, 'Enter at least 9 TIN digits.');

        $merchant = DB::table('merchants')
            ->whereRaw($this->normalizedTinSql('tin').' = ?', [$tin])
            ->first();

        if ($merchant) {
            return response()->json([
                'data' => [
                    'id'=>$merchant->id,'business_name'=>$merchant->business_name,'tin'=>$merchant->tin,'address'=>$merchant->address,'vat_registered'=>(bool)$merchant->vat_registered,
                    'type' => 'business_account',
                ],
            ]);
        }

        $profile = DB::table('profiles')->where('id',$this->userFromRequest($request)->id)
            ->whereRaw($this->normalizedTinSql('tin').' = ?', [$tin])
            ->first();

        abort_unless($profile, 404, 'No registered NUERS account was found for this TIN.');

        return response()->json([
            'data' => [
                'id' => (string) $profile->id,
                'type' => 'client_profile',
                'business_name' => $profile->organization ?: $profile->full_name,
                'owner_name' => $profile->full_name,
                'tin' => $profile->tin,
                'address' => null,
                'email' => $profile->email,
                'phone' => null,
                'rdo_code' => null,
                'rdo_name' => null,
                'status' => $profile->role,
            ],
        ]);
    }

    private function userFromRequest(Request $request): ?object
    {
        $token = $request->bearerToken();

        if (! $token) {
            return null;
        }

        return app(\App\Services\ApiAccess::class)->user($request);
    }

    private function merchantForUser(object $user): ?object
    {
        return app(\App\Services\ApiAccess::class)->merchants($user)->orderBy('id')->first();
    }

    private function merchantPayload(object $merchant, ?Request $request = null): array
    {
        return [
            'id' => (string) $merchant->id,
            'business_name' => (string) $merchant->business_name,
            'owner_name' => $merchant->owner_name,
            'tin' => $merchant->tin,
            'address' => $this->merchantAddress($merchant),
            'street_address' => $this->merchantStreetAddress($merchant),
            'email' => $merchant->email ?: $merchant->merchant_account_email,
            'phone' => $merchant->phone,
            'country' => $merchant->country ?? 'Philippines',
            'country_code' => $merchant->country_code ?? 'PH',
            'city' => $merchant->city,
            'city_code' => $merchant->city_code ?? null,
            'barangay' => $merchant->barangay ?? null,
            'barangay_code' => $merchant->barangay_code ?? null,
            'zip_code' => $merchant->zip_code,
            'region' => $merchant->region,
            'rdo_code' => $merchant->rdo_code,
            'rdo_name' => $merchant->rdo_name,
            'status' => $merchant->status,
            'sector' => $merchant->sector,
            'business_type' => $merchant->business_type,
            'bir_registration_date' => $merchant->bir_registration_date,
            'vat_registered' => (bool) $merchant->vat_registered,
            'compliance_score' => (int) ($merchant->compliance_score ?: $this->computedComplianceScore($merchant)),
            'monthly_revenue' => (float) $merchant->monthly_revenue,
            'annual_revenue' => (float) $merchant->annual_revenue,
            'employee_count' => (int) $merchant->employee_count,
            'pos_system' => $merchant->pos_system,
            'branch_count' => (int) $merchant->branch_count,
            'registration_date' => $merchant->registration_date,
            'last_audit_date' => $merchant->last_audit_date,
            'next_audit_date' => $merchant->next_audit_date,
            'website' => $merchant->website,
            'logo_url' => $this->publicAssetUrl($this->merchantColumn($merchant, 'logo_url'), $request),
            'notes' => $merchant->notes,
            'created_at' => $merchant->created_at,
            'updated_at' => $merchant->updated_at,
            'documents' => $this->profileDocuments($merchant),
            'audit_events' => $this->profileAuditEvents($merchant),
        ];
    }

    private function merchantAddress(object $merchant): ?string
    {
        $address = $this->merchantStreetAddress($merchant);

        if ($address) {
            return $address;
        }

        $parts = array_filter([
            $merchant->barangay ?? null,
            $merchant->city ?? null,
            $merchant->country ?? null,
        ]);

        return $parts ? implode(', ', $parts) : null;
    }

    private function merchantColumn(object $merchant, string $column): mixed
    {
        return property_exists($merchant, $column) ? $merchant->{$column} : null;
    }

    private function publicAssetUrl(?string $path, ?Request $request): ?string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return null;
        }

        if (preg_match('/^(https?:|data:|blob:)/i', $path)) {
            return $path;
        }

        $path = '/'.ltrim($path, '/');

        if (str_starts_with($path, '/public/')) {
            return $path;
        }

        $baseUrl = $request ? rtrim($request->getBaseUrl(), '/') : '';

        if (str_ends_with($baseUrl, '/index.php')) {
            $baseUrl = substr($baseUrl, 0, -10);
        }

        return $baseUrl ? $baseUrl.$path : $path;
    }

    private function merchantStreetAddress(object $merchant): ?string
    {
        $address = trim((string) ($merchant->address ?: $merchant->business_registration_address));
        $country = strtolower(trim((string) ($merchant->country ?? 'Philippines')));

        if ($address === '' || strtolower($address) === $country || strtolower($address) === 'ph') {
            return null;
        }

        return $address;
    }

    private function normalizeTin(string $tin): string
    {
        return preg_replace('/\D+/', '', $tin) ?: '';
    }

    private function normalizedTinSql(string $column): string
    {
        return "REPLACE(REPLACE(REPLACE(REPLACE(COALESCE({$column}, ''), '-', ''), ' ', ''), '.', ''), '/', '')";
    }

    private function profileDocuments(object $merchant): array
    {
        $state=app(\App\Services\MerchantVerificationDocuments::class)->present($merchant->id);
        return array_map(fn($d)=>['id'=>$d['kind'],'name'=>$d['name'],'type'=>$d['description'],'status'=>$d['status']==='needs_changes'?'expired':$d['status'],'uploaded'=>$d['created_at'],'expires'=>null,'size'=>$d['bytes']?round($d['bytes']/1024).' KB':null],$state['items']);
    }

    private function profileAuditEvents(object $merchant): array
    {
        if (! Schema::hasTable('audit_events')) {
            return [];
        }

        return DB::table('audit_events')
            ->where(function ($query) use ($merchant) {
                $query
                    ->where(function ($inner) use ($merchant) {
                        $inner
                            ->whereIn('auditable_type', ['merchant', 'merchants', 'business_account'])
                            ->where('auditable_id', $merchant->id);
                    })
                    ->orWhere('actor_email', $merchant->merchant_account_email)
                    ->orWhere('actor_email', $merchant->email);
            })
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($event) => [
                'id' => (string) $event->id,
                'action' => (string) $event->event_type,
                'user' => $event->actor_email ?: 'system',
                'timestamp' => $event->created_at,
                'field' => null,
                'before' => null,
                'after' => null,
            ])
            ->values()
            ->all();
    }

    private function computedComplianceScore(object $merchant): int
    {
        $checks = [
            (bool) $merchant->business_name,
            (bool) $merchant->tin,
            (bool) $merchant->bir_registration_date,
            (bool) $this->merchantAddress($merchant),
            (bool) ($merchant->email ?: $merchant->merchant_account_email),
            (bool) ($merchant->rdo_code ?? null),
            (bool) ($merchant->vat_registered ?? false),
            (bool) ($merchant->pos_system ?? null),
        ];

        return (int) round((count(array_filter($checks)) / count($checks)) * 100);
    }

    private function audit(string $eventType, string $merchantId, object $user, Request $request, array $metadata): void
    {
        if (! Schema::hasTable('audit_events')) {
            return;
        }

        DB::table('audit_events')->insert([
            'id' => (string) Str::uuid(),
            'actor_id' => $user->id,
            'actor_email' => $user->email,
            'event_type' => $eventType,
            'auditable_type' => 'merchants',
            'auditable_id' => $merchantId,
            'ip_address' => $request->ip(),
            'device_fingerprint' => null,
            'severity' => 'info',
            'metadata' => json_encode($metadata),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
