<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Transactional encrypted outbox. Network delivery is performed only by the worker. */
class PlatformNotifications
{
    public function ready(): bool
    {
        return Schema::hasTable('notification_outbox');
    }

    public function failedSignIn(User $user): void
    {
        if (! $this->ready()) {
            return;
        }
        $window = (string) intdiv(now()->timestamp, 900);
        $bucket = hash('sha256', 'failed-signin|'.$user->id.'|'.$window);
        DB::transaction(function () use ($user, $window, $bucket) {
            DB::table('api_quota_buckets')->insertOrIgnore(['id' => $bucket, 'used' => 0, 'expires_at' => now()->addMinutes(15)]);
            $row = DB::table('api_quota_buckets')->where('id', $bucket)->lockForUpdate()->first();
            DB::table('api_quota_buckets')->where('id', $bucket)->increment('used');
            if ((int) $row->used === 4) {
                $this->user($user->id, 'security.failed_signins', $window, 'Unsuccessful sign-in attempts', 'Several unsuccessful password sign-in attempts were recorded for your NUERS account. Your password has not changed. If this was not you, review account security and contact NUERS support.', 'security');
            }
        });
    }

    public function verification(User $user): void
    {
        if (! $this->ready() || $user->email_verified_at) {
            return;
        }
        $token = Str::random(64);
        DB::table('account_email_verifications')->updateOrInsert(['user_id' => (string) $user->id], ['token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay(), 'created_at' => now()]);
        $this->queue($user->email, 'security.email.verify', hash('sha256', $token), 'Verify your NUERS email', 'Confirm that this email address belongs to you. The secure link expires in 24 hours and works once. Email verification does not activate a pending taxpayer account or grant BIR authority.', 'security', (string) $user->id, action: $this->url('verify-email').'#token='.$token.'&email='.rawurlencode($user->email), expires: now()->addDay(), context: ['token_hash' => hash('sha256', $token)]);
    }

    public function url(string $path): string
    {
        // Never derive emailed links from the untrusted request Host header.
        $root = rtrim((string) config('app.url'), '/');
        abort_unless(parse_url($root, PHP_URL_HOST) && (str_starts_with($root, 'https://') || (app()->environment(['local', 'testing']) && in_array(parse_url($root, PHP_URL_HOST), ['localhost', '127.0.0.1']))), 503, 'Configure a trusted HTTPS application URL.');

        return $root.'/'.ltrim($path, '/');
    }

    public function queue(string $email, string $event, string $key, string $subject, string $body, string $category = 'workflow', ?string $userId = null, ?string $merchantId = null, ?string $action = null, ?\DateTimeInterface $expires = null, array $context = []): ?string
    {
        if (! $this->ready() || ! filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $email)) {
            return null;
        }
        if ($userId && $category !== 'security' && DB::table('notification_preferences')->where('user_id', $userId)->value($category.'_email') !== null && ! (bool) DB::table('notification_preferences')->where('user_id', $userId)->value($category.'_email')) {
            return null;
        }
        $id = (string) Str::uuid();
        $payload = ['recipient' => $email, 'subject' => $subject, 'body' => $body, 'action_url' => $action, 'context' => $context];
        $inserted = DB::table('notification_outbox')->insertOrIgnore(['id' => $id, 'dedupe_key' => hash('sha256', $event.'|'.$key.'|'.strtolower($email)), 'event' => $event, 'category' => $category,
            'user_id' => $userId, 'merchant_id' => $merchantId, 'encrypted_payload' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)), 'state' => 'queued', 'priority' => $category === 'security' ? 0 : ($category === 'customer_copy' ? 10 : ($category === 'operations' ? 30 : 20)), 'available_at' => now(), 'expires_at' => $expires ?? now()->addDays(2), 'created_at' => now(), 'updated_at' => now()]);

        return $inserted ? $id : null;
    }

    public function user(string|int|null $id, string $event, string $key, string $subject, string $body, string $category = 'workflow', ?string $merchant = null, ?string $action = null): void
    {
        if (! $id || ! $this->ready()) {
            return;
        }
        $email = DB::table('users')->where('id', $id)->value('email');
        if ($email) {
            $this->queue($email, $event, $key, $subject, $body, $category, (string) $id, $merchant, $action ?? $this->url('login'));
        }
    }

    public function admins(string $event, string $key, string $subject, string $body): void
    {
        if (! $this->ready()) {
            return;
        }
        // No regulator accounts or arbitrary contact addresses receive platform operations emails.
        foreach (DB::table('users')->join('profiles', 'profiles.id', '=', 'users.id')->whereIn('profiles.role', ['admin', 'super_admin'])->select('users.id')->cursor() as $u) {
            $this->user($u->id, $event, $key, $subject, $body, 'operations');
        }
    }

    public function merchant(?string $id, string $event, string $key, string $subject, string $body, string $category = 'workflow'): void
    {
        if (! $id || ! $this->ready()) {
            return;
        }
        $owner = DB::table('merchants')->where('id', $id)->value('merchant_account_id');
        $this->user($owner, $event, $key, $subject, $body, $category, $id);
    }

    public function runOne(): bool
    {
        if (! $this->ready()) {
            return false;
        }
        if (! config('notifications.dispatch_enabled')) {
            return false;
        }
        DB::table('notification_runtime')->where('id', 1)->update(['worker_seen_at' => now()]);
        DB::table('document_email_deliveries')->whereIn('outbox_id', DB::table('notification_outbox')->where('state', 'sending')->where('lease_until', '<', now())->select('id'))->update(['state' => 'uncertain', 'error_code' => 'worker_interrupted', 'updated_at' => now()]);
        // A crash after SMTP starts cannot safely be replayed automatically.
        DB::table('notification_outbox')->where('state', 'sending')->where('lease_until', '<', now())->update(['state' => 'uncertain', 'error_code' => 'worker_interrupted', 'encrypted_payload' => null, 'updated_at' => now()]);
        DB::table('document_email_deliveries')->whereIn('outbox_id', DB::table('notification_outbox')->where('state', 'preparing')->where('lease_until', '<', now())->where('attempts', '>=', 3)->select('id'))->update(['state' => 'failed', 'error_code' => 'preparation_interrupted', 'updated_at' => now()]);
        DB::table('notification_outbox')->where('state', 'preparing')->where('lease_until', '<', now())->where('attempts', '>=', 3)->update(['state' => 'failed', 'error_code' => 'preparation_interrupted', 'encrypted_payload' => null, 'updated_at' => now()]);
        DB::table('notification_outbox')->where('state', 'preparing')->where('lease_until', '<', now())->update(['state' => 'queued', 'lease_token' => null, 'updated_at' => now()]);
        DB::table('document_email_deliveries')->whereIn('outbox_id', DB::table('notification_outbox')->where('state', 'queued')->where('expires_at', '<=', now())->select('id'))->update(['state' => 'expired', 'updated_at' => now()]);
        DB::table('notification_outbox')->where('state', 'queued')->where('expires_at', '<=', now())->update(['state' => 'expired', 'encrypted_payload' => null, 'updated_at' => now()]);
        $settings = app(PlatformMail::class)->settings();
        if (empty($settings['enabled'])) {
            return false;
        }
        $job = DB::transaction(function () {
            $row = DB::table('notification_outbox')->where('state', 'queued')->where('available_at', '<=', now())->where('expires_at', '>', now())->orderBy('priority')->orderBy('created_at')->orderBy('id')->lockForUpdate()->first();
            if (! $row) {
                return null;
            }
            $lease = (string) Str::uuid();
            DB::table('notification_outbox')->where('id', $row->id)->update(['state' => 'preparing', 'lease_token' => $lease, 'lease_until' => now()->addMinutes(5), 'attempts' => $row->attempts + 1, 'updated_at' => now()]);
            $row->lease_token = $lease;

            return $row;
        }, 3);
        if (! $job) {
            return false;
        }
        $sending = false;
        try {
            $p = json_decode(Crypt::decryptString($job->encrypted_payload), true, flags: JSON_THROW_ON_ERROR);
            // Re-check opt-out, account existence and authoritative address at dispatch time.
            if ($job->user_id && (strtolower((string) DB::table('users')->where('id', $job->user_id)->value('email')) !== strtolower($p['recipient']) || ($job->category !== 'security' && (int) (DB::table('notification_preferences')->where('user_id', $job->user_id)->value($job->category.'_email') ?? 1) === 0))) {
                $this->finish($job, 'cancelled');

                return true;
            }
            if ($job->merchant_id && $job->user_id && (string) DB::table('merchants')->where('id', $job->merchant_id)->value('merchant_account_id') !== $job->user_id) {
                $this->finish($job, 'cancelled');

                return true;
            }
            if ($job->event === 'document.customer_copy') {
                $delivery = DB::table('document_email_deliveries')->where('id', $p['context']['delivery_id'])->where('outbox_id', $job->id)->where('merchant_id', $job->merchant_id)->first();
                $merchant = $delivery ? DB::table('merchants')->where('id', $delivery->merchant_id)->where('merchant_account_id', $delivery->actor_id)->whereNotIn('status', ['suspended', 'inactive'])->first() : null;
                $row = $delivery ? DB::table($delivery->document_source === 'invoice' ? 'business_invoices' : 'transaction_receipts')->where('id', $delivery->document_id)->where('merchant_id', $delivery->merchant_id)->first() : null;
                if (! $merchant || ! $row || $row->status === 'draft') {
                    $this->finish($job, 'cancelled');

                    return true;
                }
                $document = app(ElectronicDocumentView::class)->make($delivery->document_source, $row);
                $pdf = app(DocumentPdf::class)->render($document);
                $filename = app(DocumentPdf::class)->filename($document);
                DB::table('document_email_deliveries')->where('id', $delivery->id)->update(['pdf_hash' => hash('sha256', $pdf), 'state' => 'preparing', 'updated_at' => now()]);
            }
            if ($job->event === 'merchant.team.invitation') {
                $invite = DB::table('merchant_team_members')->where('id', $p['context']['member_id'] ?? '')->where('merchant_id', $job->merchant_id)->where('status', 'invited')->where('invite_hash', $p['context']['token_hash'] ?? '')->where('expires_at', '>', now())->first();
                if (!$invite || !DB::table('merchants')->where('id', $job->merchant_id)->whereNotIn('status', ['suspended','inactive'])->exists()) { $this->finish($job, 'cancelled'); return true; }
            }
            if ($job->event === 'business.invitation') {
                $invite = DB::table('business_registry_requests')->where('id', $p['context']['invitation_id'] ?? '')->whereNull('completed_at')->whereNull('revoked_at')->where('expires_at', '>', now())->first();
                if (! $invite || ! DB::table('merchants')->where('id', $invite->merchant_id)->where('email', $p['recipient'])->whereNull('merchant_account_id')->whereNotIn('status', ['suspended', 'inactive'])->exists()) {
                    $this->finish($job, 'cancelled');

                    return true;
                }
            }
            if ($job->event === 'security.password.reset_link') {
                $reset = DB::table('account_password_resets')->where('user_id', $job->user_id)->where('token_hash', $p['context']['token_hash'] ?? '')->where('expires_at', '>', now())->first();
                if (! $reset) {
                    $this->finish($job, 'cancelled');

                    return true;
                }
            }
            if ($job->event === 'registration.verification') {
                $challenge = DB::table('registration_email_verifications')->where('id', $p['context']['challenge_id'] ?? '')->whereNull('verified_at')->whereNull('consumed_at')->where('expires_at', '>', now())->first();
                if (! $challenge) {
                    $this->finish($job, 'cancelled');

                    return true;
                }
            }
            if ($job->event === 'security.email.verify') {
                $verification = DB::table('account_email_verifications')->where('user_id', $job->user_id)->where('token_hash', $p['context']['token_hash'] ?? '')->where('expires_at', '>', now())->first();
                if (! $verification || DB::table('users')->where('id', $job->user_id)->value('email_verified_at')) {
                    $this->finish($job, 'cancelled');

                    return true;
                }
            }
            $claimed = DB::table('notification_outbox')->where('id', $job->id)->where('lease_token', $job->lease_token)->where('state', 'preparing')->where('lease_until', '>', now())->update(['state' => 'sending', 'updated_at' => now()]);
            if (! $claimed) {
                return true;
            }
            DB::table('document_email_deliveries')->where('outbox_id', $job->id)->update(['state' => 'sending', 'updated_at' => now()]);
            $sending = true;
            if (isset($pdf)) {
                app(PlatformMail::class)->send($settings, $p['recipient'], $p['subject'], $p['body'], $pdf, $filename);
            } else {
                app(PlatformMail::class)->send($settings, $p['recipient'], $p['subject'], $p['body'], actionUrl: $p['action_url']);
            }
            $this->finish($job, 'smtp_accepted');
        } catch (\Throwable $e) {
            if ($sending) {
                $this->finish($job, 'uncertain', 'smtp_unconfirmed');
            } elseif ($job->attempts >= 2) {
                $this->finish($job, 'failed', 'preparation_failed');
            } else {
                DB::table('notification_outbox')->where('id', $job->id)->where('lease_token', $job->lease_token)->update(['state' => 'queued', 'available_at' => now()->addMinutes(5), 'error_code' => 'preparation_failed', 'updated_at' => now()]);
            }
        }

        return true;
    }

    private function finish(object $job, string $state, ?string $error = null): void
    {
        DB::transaction(function () use ($job, $state, $error) {
            $owned = DB::table('notification_outbox')->where('id', $job->id)->where('lease_token', $job->lease_token)->lockForUpdate()->first();
            if (! $owned) {
                return;
            }
            DB::table('document_email_deliveries')->where('outbox_id', $job->id)->update(['state' => $state, 'error_code' => $error, 'updated_at' => now()]);
            DB::table('notification_outbox')->where('id', $job->id)->where('lease_token', $job->lease_token)->update(['state' => $state, 'error_code' => $error, 'encrypted_payload' => null, 'lease_token' => null, 'lease_until' => null, 'accepted_at' => $state === 'smtp_accepted' ? now() : null, 'updated_at' => now()]);
        });
    }
}
