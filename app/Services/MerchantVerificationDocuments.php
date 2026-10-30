<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MerchantVerificationDocuments
{
    public const MAX_BYTES = 100 * 1024 * 1024;
    public const KINDS = [
        'bir_cor' => ['name' => 'BIR Certificate of Registration', 'description' => 'Form 2303 showing the registered business name, TIN and address.'],
        'business_registration' => ['name' => 'Business Registration Certificate', 'description' => 'DTI, SEC, CDA or the applicable business registration document.'],
        'representative_id' => ['name' => 'Owner / Authorized Representative ID', 'description' => 'A valid government-issued ID of the owner or authorized representative.'],
        'representative_authority' => ['name' => 'Proof of Authority', 'description' => 'Owner declaration, authorization letter, board resolution or secretary’s certificate, as applicable.'],
        'proof_address' => ['name' => 'Proof of Business Address', 'description' => 'Lease, utility bill or another document supporting the registered business address.'],
    ];

    public function available(): bool
    {
        return Schema::hasTable('merchant_verification_documents');
    }

    public function latest(string $merchant)
    {
        return DB::table('merchant_verification_documents')->whereIn('id', DB::table('merchant_verification_documents')->where('merchant_id', $merchant)->selectRaw('MAX(id)')->groupBy('kind'))->get()->keyBy('kind');
    }

    public function required(string $merchant): bool
    {
        return Schema::hasColumn('business_registrations', 'documents_required') && DB::table('business_registrations')->where('merchant_id', $merchant)->where('documents_required', true)->exists();
    }

    public function present(string $merchant): array
    {
        $rows = $this->available() ? $this->latest($merchant) : collect();
        $versions = $this->available() ? DB::table('merchant_verification_documents')->where('merchant_id', $merchant)->selectRaw('kind, COUNT(*) as versions')->groupBy('kind')->pluck('versions', 'kind') : collect();
        $names = $this->actorNames($rows);
        $items = [];
        foreach (self::KINDS as $kind => $meta) {
            $r = $rows[$kind] ?? null;
            $items[] = ['kind' => $kind, ...$meta, ...($r ? $this->presentRow($r, $names) : ['id' => null, 'status' => 'missing', 'filename' => null, 'bytes' => null, 'created_at' => null, 'reviewed_at' => null, 'review_note' => null]), 'version' => (int) ($versions[$kind] ?? 0), 'is_current' => true];
        }
        $uploaded = $rows->count();
        $verified = $rows->where('status', 'verified')->count();

        $account = DB::table('merchants')->where('id', $merchant)->first(['status', 'registry_details']);
        $details = json_decode($account->registry_details ?? '{}', true) ?: [];
        $status = $account->status ?? 'pending';
        $stage = $details['stage'] ?? ($status === 'active' ? 'onboarded' : 'evaluation');
        $active = $status === 'active';
        $notice = $active
            ? 'This account is onboarded and active. The registered Merchant Admin can generate API keys without another account approval.'
            : (in_array($status, ['suspended', 'inactive'], true)
                ? 'Account access is '.$status.'. The saved account-access decision applies even when all documents are verified.'
                : ($verified === count(self::KINDS)
                    ? 'All document categories are verified. Complete the initial onboarding decision to activate this account.'
                    : 'Upload the required documents and record their review decisions before completing account activation.'));

        return ['items' => $items, 'required' => $this->required($merchant), 'uploaded' => $uploaded, 'verified' => $verified, 'total' => count(self::KINDS),
            'ready_for_activation' => $verified === count(self::KINDS),
            'account' => ['status' => $status, 'stage' => $stage, 'active' => $active], 'notice' => $notice];
    }

    public function actorNames($rows): array
    {
        return DB::table('users')->whereIn('id', $rows->flatMap(fn ($r) => [$r->uploaded_by, $r->reviewed_by])->filter()->unique())->pluck('name', 'id')->all();
    }

    public function presentRow(object $r, array $names): array
    {
        return ['id' => $r->document_id, 'kind' => $r->kind, ...self::KINDS[$r->kind], 'status' => $r->status,
            'filename' => Crypt::decryptString($r->filename_encrypted), 'bytes' => (int) $r->bytes, 'mime' => $r->mime, 'sha256' => $r->sha256,
            'created_at' => $r->created_at, 'uploaded_by' => $names[$r->uploaded_by] ?? ($r->uploaded_by ? 'Account user' : 'Registration upload'),
            'reviewed_at' => $r->reviewed_at, 'reviewed_by' => $names[$r->reviewed_by] ?? null,
            'review_note' => $r->review_encrypted ? Crypt::decryptString($r->review_encrypted) : null];
    }

    public function store(?string $merchant, ?string $challenge, string $kind, UploadedFile $file, ?string $actor): object
    {
        abort_unless($this->available(), 503, 'Verification document storage is not available.');
        abort_unless($file->getSize() > 0 && $file->getSize() <= self::MAX_BYTES, 422, 'Choose a document no larger than 100 MB.');
        $sha = hash_file('sha256', $file->getRealPath());
        $scope = DB::table('merchant_verification_documents')->where('merchant_id', $merchant)->when(! $merchant, fn ($q) => $q->where('challenge_id', $challenge))->where('kind', $kind);
        $last = (clone $scope)->orderByDesc('id')->first();
        // An explicit resubmission after a correction request is a new reviewable version,
        // even when the merchant selects the same bytes. Pending/verified retries still dedupe.
        if ($last && $last->status !== 'needs_changes' && hash_equals($last->sha256, $sha)) {
            return $last;
        }
        abort_if(! $merchant && (clone $scope)->count() >= 2, 429, 'Staged file limit reached. Complete registration, then manage documents in Business Profile.');
        $uuid = (string) Str::uuid();
        $path = 'merchant-verification/'.($merchant ?? 'staging').'/'.$uuid.'.enc';
        app(PrivateDocumentStream::class)->store($file->getRealPath(), $path);
        try {
            $id = DB::table('merchant_verification_documents')->insertGetId(['document_id' => $uuid, 'merchant_id' => $merchant, 'challenge_id' => $challenge, 'kind' => $kind, 'status' => 'pending', 'filename_encrypted' => Crypt::encryptString(basename($file->getClientOriginalName())), 'private_path' => $path, 'sha256' => $sha, 'bytes' => $file->getSize(), 'mime' => $file->getMimeType(), 'uploaded_by' => $actor, 'created_at' => now(), 'claimed_at' => $merchant ? now() : null]);
        } catch (\Throwable$e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        return DB::table('merchant_verification_documents')->where('id', $id)->first();
    }

    public function claim(string $merchant, string $challenge, array $ids, string $actor): void
    {
        if (! $ids) {
            return;
        }abort_unless($this->available(), 503, 'Verification storage is not available.');
        $rows = DB::table('merchant_verification_documents')->whereNull('merchant_id')->where('challenge_id', $challenge)->whereIn('document_id', $ids)->lockForUpdate()->get();
        abort_unless($rows->count() === count($ids) && $rows->pluck('kind')->unique()->count() === count($ids), 422, 'One or more uploads do not belong to this registration. Upload the selected files again.');
        DB::table('merchant_verification_documents')->whereIn('id', $rows->pluck('id'))->update(['merchant_id' => $merchant, 'uploaded_by' => $actor, 'claimed_at' => now()]);
    }

    public function assertActivation(string $merchant): void
    {
        if (! $this->required($merchant)) {
            return;
        }
        $p = $this->present($merchant);
        abort_unless($p['ready_for_activation'], 422, 'All required verification documents must be uploaded and independently verified before activating this account.');
        foreach ($this->latest($merchant) as $row) {
            $this->assertIntegrity($row);
        }
    }

    public function contents(object $row): string
    {
        $stream = app(PrivateDocumentStream::class)->verified($row);
        try {
            return stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    public function assertIntegrity(object $row): void
    {
        fclose(app(PrivateDocumentStream::class)->verified($row));
    }
}
