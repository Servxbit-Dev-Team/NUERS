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
        $items = [];
        foreach (self::KINDS as $kind => $meta) {
            $r = $rows[$kind] ?? null;
            $items[] = ['kind' => $kind, ...$meta, 'id' => $r?->document_id, 'status' => $r?->status ?? 'missing', 'filename' => $r ? Crypt::decryptString($r->filename_encrypted) : null, 'bytes' => $r?->bytes, 'created_at' => $r?->created_at, 'reviewed_at' => $r?->reviewed_at, 'review_note' => $r?->review_encrypted ? Crypt::decryptString($r->review_encrypted) : null];
        }
        $uploaded = $rows->count();
        $verified = $rows->where('status', 'verified')->count();

        return ['items' => $items, 'required' => $this->required($merchant), 'uploaded' => $uploaded, 'verified' => $verified, 'total' => count(self::KINDS), 'ready_for_activation' => $verified === count(self::KINDS), 'notice' => 'Documents are required for NUERS account verification and activation. Uploading a file does not verify the account or establish BIR approval.'];
    }

    public function store(?string $merchant, ?string $challenge, string $kind, UploadedFile $file, ?string $actor): object
    {
        abort_unless($this->available(), 503, 'Verification document storage is not available.');
        $raw = file_get_contents($file->getRealPath());
        $sha = hash('sha256', $raw);
        $scope = DB::table('merchant_verification_documents')->where('merchant_id', $merchant)->when(! $merchant, fn ($q) => $q->where('challenge_id', $challenge))->where('kind', $kind);
        $last = (clone $scope)->orderByDesc('id')->first();
        if ($last && hash_equals($last->sha256, $sha)) {
            return $last;
        }
        abort_if(! $merchant && (clone $scope)->count() >= 2, 429, 'Staged file limit reached. Complete registration, then manage documents in Business Profile.');
        $uuid = (string) Str::uuid();
        $path = 'merchant-verification/'.($merchant ?? 'staging').'/'.$uuid.'.enc';
        abort_unless(Storage::disk('local')->put($path, Crypt::encryptString(base64_encode($raw))), 503, 'Private document storage is unavailable.');
        try {
            $id = DB::table('merchant_verification_documents')->insertGetId(['document_id' => $uuid, 'merchant_id' => $merchant, 'challenge_id' => $challenge, 'kind' => $kind, 'status' => 'pending', 'filename_encrypted' => Crypt::encryptString(basename($file->getClientOriginalName())), 'private_path' => $path, 'sha256' => $sha, 'bytes' => strlen($raw), 'mime' => $file->getMimeType(), 'uploaded_by' => $actor, 'created_at' => now(), 'claimed_at' => $merchant ? now() : null]);
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
            $this->contents($row);
        }
    }

    public function contents(object $row): string
    {
        abort_unless(Storage::disk('local')->exists($row->private_path), 409, 'The private document is unavailable. Upload a new copy for review.');
        try {
            $raw = base64_decode(Crypt::decryptString(Storage::disk('local')->get($row->private_path)), true);
        } catch (\Throwable) {
            abort(409, 'Document integrity check failed. Upload a new copy for review.');
        }
        abort_unless($raw !== false && hash_equals($row->sha256, hash('sha256', $raw)), 409, 'Document integrity check failed. Upload a new copy for review.');

        return $raw;
    }
}
