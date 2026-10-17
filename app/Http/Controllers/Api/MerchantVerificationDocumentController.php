<?php

namespace App\Http\Controllers\Api;

use App\Services\ApiAccess;
use App\Services\BirCompliance;
use App\Services\MerchantVerificationDocuments;
use App\Services\RegistrationVerification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MerchantVerificationDocumentController
{
    private function service(): MerchantVerificationDocuments
    {
        return app(MerchantVerificationDocuments::class);
    }

    private function file(Request $r): array
    {
        return $r->validate(['kind' => ['required', Rule::in(array_keys(MerchantVerificationDocuments::KINDS))], 'file' => 'required|file|max:2048|mimes:pdf,png,jpg,jpeg']);
    }

    public function stage(Request $r)
    {
        $v = $r->validate(['challenge_id' => 'required|uuid', 'verification_token' => 'required|string|size:64']);
        $f = $this->file($r);

        return DB::transaction(function () use ($v, $f, $r) {
            $c = DB::table('registration_email_verifications')->where('id', $v['challenge_id'])->lockForUpdate()->first();
            app(RegistrationVerification::class)->requireValid($c, $v['verification_token']);
            $d = $this->service()->store(null, $c->id, $f['kind'], $r->file('file'), null);

            return response()->json(['id' => $d->document_id, 'kind' => $d->kind, 'status' => 'pending'], 201);
        });
    }

    public function index(Request $r, ?string $merchant = null)
    {
        if ($merchant) {
            app(ApiAccess::class)->require($r, ['admin', 'super_admin']);
            abort_unless(DB::table('merchants')->where('id', $merchant)->exists(), 404);
        } else {
            $merchant = app(ApiAccess::class)->merchant($r)->id;
        }

        return response()->json($this->service()->present($merchant))->header('Cache-Control', 'private, no-store');
    }

    public function upload(Request $r)
    {
        $m = app(ApiAccess::class)->merchant($r);
        $v = $this->file($r);

        return DB::transaction(function () use ($r, $m, $v) {
            $m = DB::table('merchants')->where('id', $m->id)->lockForUpdate()->first();
            $last = DB::table('merchant_verification_documents')->where('merchant_id', $m->id)->where('kind', $v['kind'])->orderByDesc('id')->value('id');
            $d = $this->service()->store($m->id, null, $v['kind'], $r->file('file'), (string) $r->attributes->get('api_actor')->id);
            if ($d->id !== $last) {
                $updates = ['registry_revision' => $m->registry_revision + 1, 'updated_at' => now()];
                if ($this->service()->required($m->id) && $m->status === 'active') {
                    $meta = json_decode($m->registry_details ?? '{}', true) ?: [];
                    $meta['stage'] = 'evaluation';
                    $meta['checks'] = [];
                    $updates += ['status' => 'pending', 'registry_details' => json_encode($meta)];
                }DB::table('merchants')->where('id', $m->id)->update($updates);
                app(BirCompliance::class)->audit('business.verification.document_uploaded', $m->id, (string) $r->attributes->get('api_actor')->id, ['document_id' => $d->document_id, 'kind' => $d->kind]);
            }

            return response()->json($this->service()->present($m->id));
        });
    }

    public function download(Request $r, string $id)
    {
        $admin = $r->is('api/admin/*');
        $access = app(ApiAccess::class);
        if ($admin) {
            $access->require($r, ['admin', 'super_admin']);
        }$m = $admin ? null : $access->merchant($r);
        $d = DB::table('merchant_verification_documents')->where('document_id', $id)->whereNotNull('merchant_id')->when($m, fn ($q) => $q->where('merchant_id', $m->id))->first();
        abort_unless($d, 404);
        $raw = $this->service()->contents($d);
        $ext = match ($d->mime) {
            'application/pdf' => 'pdf','image/png' => 'png',default => 'jpg'
        };
        app(BirCompliance::class)->audit('business.verification.document_downloaded', $d->merchant_id, (string) $r->attributes->get('api_actor')->id, ['document_id' => $d->document_id]);

        return response($raw)->header('Content-Type', $d->mime)->header('Content-Disposition', 'attachment; filename="NUERS-verification-'.$d->kind.'.'.$ext.'"')->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    public function review(Request $r, string $id)
    {
        $u = app(ApiAccess::class)->require($r, ['admin', 'super_admin']);
        $v = $r->validate(['decision' => 'required|in:verified,needs_changes', 'reason' => 'required|string|min:10|max:1000']);

        return DB::transaction(function () use ($u, $v, $id) {
            $d = DB::table('merchant_verification_documents')->where('document_id', $id)->whereNotNull('merchant_id')->first();
            abort_unless($d, 404);
            $m = DB::table('merchants')->where('id', $d->merchant_id)->lockForUpdate()->first();
            abort_if((string) $m->merchant_account_id === (string) $u->id || (string) $d->uploaded_by === (string) $u->id, 403, 'Another administrator must review this evidence.');
            $latest = DB::table('merchant_verification_documents')->where('merchant_id', $m->id)->where('kind', $d->kind)->orderByDesc('id')->first();
            abort_unless($latest->id === $d->id, 409, 'A newer file has been uploaded. Refresh before reviewing.');
            abort_unless($d->status === 'pending', 409, 'This file already has a review decision. Refresh the document list.');
            if ($v['decision'] === 'verified') {
                $this->service()->contents($d);
            }DB::table('merchant_verification_documents')->where('id', $d->id)->update(['status' => $v['decision'], 'reviewed_by' => (string) $u->id, 'review_encrypted' => Crypt::encryptString($v['reason']), 'reviewed_at' => now()]);
            DB::table('merchants')->where('id', $m->id)->increment('registry_revision');
            app(BirCompliance::class)->audit('business.verification.document_reviewed', $m->id, (string) $u->id, ['document_id' => $id, 'decision' => $v['decision']]);

            return response()->json($this->service()->present($m->id));
        });
    }
}
