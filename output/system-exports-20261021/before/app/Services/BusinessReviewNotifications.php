<?php

namespace App\Services;

use Illuminate\Support\Facades\{Crypt, DB};

/** Saved decisions only. Email never changes verification, access or BIR authority. */
class BusinessReviewNotifications
{
    public const KINDS = ['changes_requested' => 'Changes requested', 'documents_verified' => 'Documents verified', 'account_approved' => 'Account approved'];

    public function state(string $id): array
    {
        $m = DB::table('merchants')->where('id', $id)->first();
        abort_unless($m, 404);
        $owner = $m->merchant_account_id ? DB::table('users')->where('id', $m->merchant_account_id)->first() : null;
        $docs = app(MerchantVerificationDocuments::class)->latest($id);
        $meta = json_decode($m->registry_details ?? '{}', true) ?: [];
        $verified = $docs->count() === count(MerchantVerificationDocuments::KINDS) && $docs->every(fn ($d) => $d->status === 'verified' && $d->reviewed_at && $d->reviewed_by && (string) $d->reviewed_by !== (string) $m->merchant_account_id);
        $changes = $docs->where('status', 'needs_changes');
        $checks = collect(['registration_certificate', 'identity', 'business_information', 'representative_authority', 'contact_verification'])->every(fn ($key) => ($meta['checks'][$key] ?? false) === true);
        $allowed = $owner && filter_var($owner->email, FILTER_VALIDATE_EMAIL) && DB::table('profiles')->where('id', $owner->id)->value('role') === 'merchant';
        $fingerprint = hash('sha256', json_encode([$m->id, $m->merchant_account_id, $owner?->email, $m->registry_revision, $m->status, $meta, $docs->map(fn ($d) => [$d->document_id, $d->sha256, $d->status, $d->reviewed_by, $d->reviewed_at, hash('sha256', $d->review_encrypted ?? '')])->values()->all()], JSON_THROW_ON_ERROR));
        $drafts = [];
        foreach (self::KINDS as $kind => $label) {
            $eligible = match ($kind) {
                'changes_requested' => $changes->isNotEmpty() && $m->status === 'pending',
                'documents_verified' => $verified && in_array($m->status, ['pending', 'active'], true),
                'account_approved' => $verified && $checks && ! empty($meta['evidence']) && $m->status === 'active' && ($meta['stage'] ?? '') === 'onboarded',
            };
            $reason = ! $allowed ? 'A linked merchant account with a valid sign-in email is required.' : match ($kind) {
                'changes_requested' => 'Save a Needs changes decision on a current document while the account is pending.',
                'documents_verified' => 'All five current documents must be independently verified.',
                'account_approved' => 'Save the completed onboarding checklist and account activation after verifying all five documents.',
            };
            $body = 'Business: '.$m->business_name."\n\n";
            if ($kind === 'changes_requested') {
                $body .= "NUERS reviewed your business verification documents and requires changes before account approval.\n\n";
                foreach ($changes as $d) {
                    $note = $d->review_encrypted ? Crypt::decryptString($d->review_encrypted) : 'Open Business Profile to review the required correction.';
                    $body .= MerchantVerificationDocuments::KINDS[$d->kind]['name'].': '.$note."\n\n";
                }
                $body .= 'Open Merchant Dashboard → Business Profile → Required Documents, replace the files marked Needs changes, then submit the complete checklist for review. Your account remains pending.';
            } elseif ($kind === 'documents_verified') {
                $body .= 'All five current business verification documents have been independently verified in NUERS. Account activation is a separate administrator decision. Open Business Profile to check the current account status; document verification alone does not authorize transactions.';
            } else {
                $body .= 'Your NUERS business account has been approved and activated after independent verification of the required documents and onboarding checklist. Sign in to your Merchant Dashboard to manage your account. Transaction processing remains subject to your wallet balance, permissions and applicable integration requirements.';
            }
            $body .= "\n\nNUERS account approval and document verification do not establish BIR approval, PTI/PTT authority or BIR EIS transmission acceptance. Never send passwords, authenticator codes or API secrets by email.";
            $drafts[$kind] = ['kind' => $kind, 'label' => $label, 'eligible' => (bool) ($allowed && $eligible), 'blocker' => $allowed && $eligible ? null : $reason, 'subject' => match ($kind) {'changes_requested' => 'Changes required for your business verification', 'documents_verified' => 'Your business documents are verified', 'account_approved' => 'Your NUERS business account is approved'}, 'body' => $body];
        }

        return ['merchant_id' => $id, 'owner_id' => $owner ? (string) $owner->id : null, 'recipient' => $owner?->email, 'business_name' => $m->business_name, 'fingerprint' => $fingerprint, 'revision' => (int) $m->registry_revision, 'drafts' => $drafts];
    }

    public function history(string $id): array
    {
        return DB::table('business_review_notifications as n')->join('notification_outbox as o', 'o.id', '=', 'n.outbox_id')->leftJoin('users as u', 'u.id', '=', 'n.actor_id')->where('n.merchant_id', $id)->orderByDesc('n.created_at')->orderByDesc('n.id')->limit(20)->get(['n.*', 'o.state', 'o.attempts', 'o.accepted_at', 'o.error_code', 'u.name as actor_name'])->map(function ($row) {
            $snapshot = json_decode(Crypt::decryptString($row->encrypted_snapshot), true, flags: JSON_THROW_ON_ERROR);
            return ['id' => $row->id, 'kind' => $row->kind, 'label' => self::KINDS[$row->kind], 'recipient' => $snapshot['recipient'], 'subject' => $snapshot['subject'], 'body' => $snapshot['body'], 'reason' => $snapshot['resend_reason'] ?? null, 'actor' => $row->actor_name ?? 'Administrator', 'created_at' => \Carbon\CarbonImmutable::parse($row->created_at, config('app.timezone'))->toIso8601String(), 'state' => $row->state, 'attempts' => (int) $row->attempts, 'accepted_at' => $row->accepted_at ? \Carbon\CarbonImmutable::parse($row->accepted_at, config('app.timezone'))->toIso8601String() : null, 'error_code' => $row->error_code];
        })->all();
    }

    public function dispatchable(object $job, array $payload): bool
    {
        $record = DB::table('business_review_notifications')->where('id', $payload['context']['review_notification_id'] ?? '')->where('outbox_id', $job->id)->where('merchant_id', $job->merchant_id)->first();
        if (! $record || ! DB::table('profiles')->where('id', $record->actor_id)->whereIn('role', ['admin', 'super_admin'])->exists()) {
            return false;
        }
        if (! DB::table('merchants')->where('id', $job->merchant_id)->exists()) {
            return false;
        }
        $state = $this->state($job->merchant_id);

        return ($state['drafts'][$record->kind]['eligible'] ?? false) && hash_equals($record->decision_fingerprint, $state['fingerprint']) && $state['recipient'] === $payload['recipient'];
    }
}
