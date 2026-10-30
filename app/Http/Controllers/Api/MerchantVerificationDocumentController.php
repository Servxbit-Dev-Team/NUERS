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
        return $r->validate(['kind' => ['required', Rule::in(array_keys(MerchantVerificationDocuments::KINDS))], 'file' => 'required|file|max:102400|mimes:pdf,png,jpg,jpeg']);
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
            $last = DB::table('merchant_verification_documents')->where('merchant_id', $m->id)->where('kind', $v['kind'])->orderByDesc('id')->first(['id', 'document_id', 'sha256', 'status']);
            $d = $this->service()->store($m->id, null, $v['kind'], $r->file('file'), (string) $r->attributes->get('api_actor')->id);
            $newVersion = $d->id !== ($last?->id);
            if ($newVersion) {
                $updates = ['registry_revision' => $m->registry_revision + 1, 'updated_at' => now()];
                if ($this->service()->required($m->id) && $m->status === 'active') {
                    $meta = json_decode($m->registry_details ?? '{}', true) ?: [];
                    $meta['stage'] = 'evaluation';
                    $meta['checks'] = [];
                    $updates += ['status' => 'pending', 'registry_details' => json_encode($meta)];
                }DB::table('merchants')->where('id', $m->id)->update($updates);
                app(BirCompliance::class)->audit('business.verification.document_uploaded', $m->id, (string) $r->attributes->get('api_actor')->id, ['document_id' => $d->document_id, 'kind' => $d->kind, 'supersedes_document_id' => $last?->document_id, 'same_content_resubmission' => $last && hash_equals($last->sha256, $d->sha256)]);
            }

            return response()->json([...$this->service()->present($m->id), 'upload_result' => ['kind' => $d->kind, 'document_id' => $d->document_id, 'new_version' => $newVersion, 'status' => $d->status, 'same_content' => $last && hash_equals($last->sha256, $d->sha256)]])->header('Cache-Control', 'private, no-store');
        });
    }

    public function download(Request $r, string $id)
    {
        return $this->serve($r, $id, false);
    }

    public function preview(Request $r, string $id)
    {
        app(ApiAccess::class)->require($r, ['admin', 'super_admin']);
        return $this->serve($r, $id, true);
    }

    public function history(Request $r, string $merchant)
    {
        app(ApiAccess::class)->require($r, ['admin', 'super_admin']);
        abort_unless(DB::table('merchants')->where('id', $merchant)->exists(), 404);
        $v = $r->validate(['kind' => ['required', Rule::in(array_keys(MerchantVerificationDocuments::KINDS))], 'page' => 'sometimes|integer|min:1|max:10000']);
        $q = DB::table('merchant_verification_documents')->where('merchant_id', $merchant)->where('kind', $v['kind']);
        $total = (clone $q)->count();
        $page = $r->integer('page', 1);
        $offset = ($page - 1) * 20;
        $current = (clone $q)->max('id');
        $rows = $q->orderByDesc('id')->offset($offset)->limit(20)->get();
        $names = $this->service()->actorNames($rows);
        return response()->json(['data' => $rows->values()->map(fn ($d, $i) => [...$this->service()->presentRow($d, $names), 'version' => $total - $offset - $i, 'is_current' => (int) $d->id === (int) $current]), 'page' => $page, 'last_page' => max(1, (int) ceil($total / 20)), 'total' => $total])->header('Cache-Control', 'private, no-store');
    }

    private function previewProof(Request $r, object $d, bool $available): string
    {
        return Crypt::encryptString(json_encode(['actor' => (string) $r->attributes->get('api_actor')->id, 'session' => hash('sha256', (string) $r->bearerToken()), 'document' => $d->document_id, 'sha256' => $d->sha256, 'source_available' => $available, 'expires' => now()->addMinutes(30)->timestamp], JSON_THROW_ON_ERROR));
    }

    private function serve(Request $r, string $id, bool $inline)
    {
        $admin = $r->is('api/admin/*');
        $access = app(ApiAccess::class);
        if ($admin) {
            $access->require($r, ['admin', 'super_admin']);
        }$m = $admin ? null : $access->merchant($r);
        $d = DB::table('merchant_verification_documents')->where('document_id', $id)->whereNotNull('merchant_id')->when($m, fn ($q) => $q->where('merchant_id', $m->id))->first();
        abort_unless($d, 404);
        abort_unless(in_array($d->mime, ['application/pdf', 'image/png', 'image/jpeg'], true), 415, 'This file type cannot be previewed.');
        try {
            $stream = app(\App\Services\PrivateDocumentStream::class)->verified($d);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            if (! $inline || $e->getStatusCode() !== 409) {
                throw $e;
            }
            app(BirCompliance::class)->audit('business.verification.document_preview_failed', $d->merchant_id, (string) $r->attributes->get('api_actor')->id, ['document_id' => $d->document_id, 'document_sha256' => $d->sha256]);
            return response()->json(['message' => $e->getMessage(), 'preview_token' => $this->previewProof($r, $d, false), 'sha256' => $d->sha256], 409)->header('Cache-Control', 'private, no-store');
        }
        $ext = match ($d->mime) {
            'application/pdf' => 'pdf','image/png' => 'png',default => 'jpg'
        };
        app(BirCompliance::class)->audit($inline ? 'business.verification.document_viewed' : 'business.verification.document_downloaded', $d->merchant_id, (string) $r->attributes->get('api_actor')->id, ['document_id' => $d->document_id, 'document_sha256' => $d->sha256]);
        $headers = ['Content-Type' => $d->mime, 'Content-Length' => (string) $d->bytes, 'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="NUERS-verification-'.$d->kind.'.'.$ext.'"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Document-SHA256' => $d->sha256];
        if ($inline) {
            $headers['X-Verification-Preview'] = $this->previewProof($r, $d, true);
        }

        return response()->stream(
            function () use ($stream) {
                try {
                    fpassthru($stream);
                } finally {
                    fclose($stream);
                }
            },
            200,
            $headers
        );
    }

    public function review(Request $r, string $id)
    {
        $u = app(ApiAccess::class)->require($r, ['admin', 'super_admin']);
        $v = $r->validate(['decision' => 'required|in:verified,needs_changes', 'reason' => 'required|string|min:10|max:1000',
            'preview_token' => 'required|string|max:2048', 'expected_sha256' => 'required|string|size:64', 'confirmed' => 'required|accepted',
            'checks' => 'required|array:readable,identity_match,complete', 'checks.readable' => 'required|boolean', 'checks.identity_match' => 'required|boolean', 'checks.complete' => 'required|boolean']);

        return DB::transaction(function () use ($u, $v, $id, $r) {
            $d = DB::table('merchant_verification_documents')->where('document_id', $id)->whereNotNull('merchant_id')->first();
            abort_unless($d, 404);
            $m = DB::table('merchants')->where('id', $d->merchant_id)->lockForUpdate()->first();
            abort_if((string) $m->merchant_account_id === (string) $u->id || (string) $d->uploaded_by === (string) $u->id, 403, 'Another administrator must review this evidence.');
            $latest = DB::table('merchant_verification_documents')->where('merchant_id', $m->id)->where('kind', $d->kind)->orderByDesc('id')->first();
            abort_unless($latest->id === $d->id, 409, 'A newer file has been uploaded. Refresh before reviewing.');
            abort_unless(hash_equals($d->sha256, $v['expected_sha256']), 409, 'The document version changed. Reopen its preview.');
            try {
                $proof = json_decode(Crypt::decryptString($v['preview_token']), true, flags: JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                abort(422, 'Open the document preview again before reviewing.');
            }
            abort_unless(($proof['actor'] ?? null) === (string) $u->id && ($proof['document'] ?? null) === $d->document_id
                && hash_equals($d->sha256, (string) ($proof['sha256'] ?? '')) && hash_equals(hash('sha256', (string) $r->bearerToken()), (string) ($proof['session'] ?? ''))
                && (int) ($proof['expires'] ?? 0) > now()->timestamp, 422, 'Your preview expired or belongs to another session. Reopen this document.');
            if ($d->status !== 'pending') {
                if ($d->status === $v['decision'] && (string) $d->reviewed_by === (string) $u->id && $d->review_encrypted && hash_equals(Crypt::decryptString($d->review_encrypted), $v['reason'])) {
                    return response()->json($this->service()->present($m->id))->header('Cache-Control', 'private, no-store');
                }
                abort(409, 'This file already has a review decision. Refresh the document list.');
            }
            if ($v['decision'] === 'verified') {
                abort_unless(($proof['source_available'] ?? false) === true, 422, 'This file could not be opened. Request a replacement instead of verifying it.');
                abort_unless($v['checks']['readable'] && $v['checks']['identity_match'] && $v['checks']['complete'], 422, 'Complete all document checks before marking this file verified.');
                $this->service()->assertIntegrity($d);
            }DB::table('merchant_verification_documents')->where('id', $d->id)->update(['status' => $v['decision'], 'reviewed_by' => (string) $u->id, 'review_encrypted' => Crypt::encryptString($v['reason']), 'reviewed_at' => now()]);
            DB::table('merchants')->where('id', $m->id)->increment('registry_revision');
            app(BirCompliance::class)->audit('business.verification.document_reviewed', $m->id, (string) $u->id, ['document_id' => $id, 'document_sha256' => $d->sha256, 'before_status' => $d->status, 'after_status' => $v['decision'], 'decision' => $v['decision'], 'checks' => $v['checks'], 'evidence_reference' => $id]);

            return response()->json($this->service()->present($m->id));
        });
    }
}
