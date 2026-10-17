<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Services\ApiAccess;
use App\Services\AtomicQuota;
use App\Services\BirCompliance;
use App\Services\NotificationEvents;
use App\Services\PlatformMail;
use App\Services\PlatformNotifications;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AccountSecurityController
{
    private function passwordRules(): array
    {
        return ['required', 'string', 'confirmed', 'max:72', Password::min(12)->letters()->numbers(), function ($attribute, $value, $fail) {
            if (strlen($value) > 72) {
                $fail('The password must not exceed 72 bytes.');
            }
        }];
    }

    public function resendVerification(Request $r)
    {
        $u = app(ApiAccess::class)->require($r);
        app(AtomicQuota::class)->admit('email-verification:'.$u->id, 5, 1);
        abort_unless(! empty(app(PlatformMail::class)->settings()['enabled']), 503, 'Email delivery is temporarily unavailable.');
        DB::transaction(fn () => app(PlatformNotifications::class)->verification($u));

        return ['message' => 'If your email is not yet verified, a new verification link is queued.'];
    }

    public function verifyEmail(Request $r)
    {
        $v = $r->validate(['email' => 'required|email:rfc|max:254', 'token' => 'required|string|size:64']);
        app(AtomicQuota::class)->admit('email-verify-ip:'.hash('sha256', $r->ip()), 50, 10);
        DB::transaction(function () use ($v) {
            $u = User::whereRaw('LOWER(email) = ?', [strtolower(trim($v['email']))])->lockForUpdate()->first();
            $proof = $u ? DB::table('account_email_verifications')->where('user_id', $u->id)->lockForUpdate()->first() : null;
            abort_unless($proof && now()->lt($proof->expires_at) && hash_equals($proof->token_hash, hash('sha256', $v['token'])), 422, 'The verification link is invalid or expired. Request a new link in Security & Email.');
            DB::table('users')->where('id', $u->id)->update(['email_verified_at' => now()]);
            DB::table('account_email_verifications')->where('user_id', $u->id)->delete();
            app(PlatformNotifications::class)->user($u->id, 'security.email.verified', (string) Str::uuid(), 'Your email has been verified', 'Your sign-in email has been verified for your NUERS account. Account review and taxpayer authority requirements remain separate.', 'security');
        });

        return response()->json(['message' => 'Your NUERS email has been verified. You can sign in to review your account.'])->header('Cache-Control', 'no-store');
    }

    public function forgot(Request $r)
    {
        $v = $r->validate(['email' => 'required|email:rfc|max:254']);
        $email = strtolower(trim($v['email']));
        app(AtomicQuota::class)->admit('password-reset-ip:'.hash('sha256', $r->ip()), 20, 3);
        $n = app(PlatformNotifications::class);
        abort_unless($n->ready() && ! empty(app(PlatformMail::class)->settings()['enabled']), 503, 'Email recovery is temporarily unavailable. Please contact NUERS support.');
        DB::transaction(function () use ($email, $n) {
            $u = User::whereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->first();
            if (! $u) {
                return;
            }
            $old = DB::table('account_password_resets')->where('user_id', $u->id)->first();
            if ($old && now()->diffInSeconds($old->created_at, true) < 60) {
                return;
            }
            $token = Str::random(64);
            DB::table('account_password_resets')->updateOrInsert(['user_id' => (string) $u->id], ['token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(30), 'created_at' => now()]);
            DB::table('notification_outbox')->where('user_id', (string) $u->id)->where('event', 'security.password.reset_link')->where('state', 'queued')->update(['state' => 'cancelled', 'encrypted_payload' => null, 'updated_at' => now()]);
            $n->queue($u->email, 'security.password.reset_link', hash('sha256', $token), 'Reset your password', 'We received a request to reset your NUERS password. Use the secure button below within 30 minutes. The link works once. If you did not request this, you can ignore this email. Your password has not changed.', 'security', (string) $u->id, action: $n->url('reset-password').'#token='.$token.'&email='.rawurlencode($u->email), expires: now()->addMinutes(30), context: ['token_hash' => hash('sha256', $token)]);
        });

        return response()->json(['message' => 'If a NUERS account matches this address, a password reset email will be sent. Check your inbox and spam folder.'])->header('Cache-Control', 'no-store');
    }

    public function reset(Request $r)
    {
        $v = $r->validate(['email' => 'required|email:rfc|max:254', 'token' => 'required|string|size:64', 'password' => $this->passwordRules()]);
        app(AtomicQuota::class)->admit('password-reset-attempt:'.hash('sha256', $r->ip()), 50, 10);
        DB::transaction(function () use ($v) {
            $u = User::whereRaw('LOWER(email) = ?', [strtolower(trim($v['email']))])->lockForUpdate()->first();
            $token = $u ? DB::table('account_password_resets')->where('user_id', $u->id)->lockForUpdate()->first() : null;
            abort_unless($token && now()->lt($token->expires_at) && hash_equals($token->token_hash, hash('sha256', $v['token'])), 422, 'The reset link is invalid or expired. Request a new link.');
            abort_if(Hash::check($v['password'], $u->password), 422, 'Choose a different password.');
            $this->replace($u, $v['password'], 'security.password.reset');
        });

        return response()->json(['message' => 'Your password has been reset. Sign in again. Your authenticator protection remains active.'])->header('Cache-Control', 'no-store');
    }

    public function change(Request $r)
    {
        $actor = app(ApiAccess::class)->require($r);
        $v = $r->validate(['current_password' => 'required|string|max:255', 'password' => $this->passwordRules()]);
        app(AtomicQuota::class)->admit('password-change:'.$actor->id, 30, 5);
        DB::transaction(function () use ($v, $actor, $r) {
            $u = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($u->api_token_hash && hash_equals($u->api_token_hash, hash('sha256', (string) $r->bearerToken())), 401, 'Sign in again.');
            abort_unless(Hash::check($v['current_password'], $u->password), 422, 'Your current password is incorrect.');
            abort_if(Hash::check($v['password'], $u->password), 422, 'Choose a different password.');
            $this->replace($u, $v['password'], 'security.password.changed');
        });

        return response()->json(['message' => 'Your password has changed. All NUERS sessions have been signed out. Sign in with your new password.'])->header('Cache-Control', 'no-store');
    }

    private function replace(User $u, string $password, string $event): void
    {
        DB::table('users')->where('id', $u->id)->update(['password' => Hash::make($password), 'remember_token' => null, 'api_token' => null, 'api_token_hash' => null, 'api_device_hash' => null, 'api_expires_at' => null, 'api_authenticated_at' => null, 'api_mfa_verified_at' => null, 'updated_at' => now()]);
        DB::table('sessions')->where('user_id', $u->id)->delete();
        DB::table('account_password_resets')->where('user_id', $u->id)->delete();
        DB::table('notification_outbox')->where('user_id', (string) $u->id)->where('event', 'security.password.reset_link')->where('state', 'queued')->update(['state' => 'cancelled', 'encrypted_payload' => null, 'updated_at' => now()]);
        app(PlatformNotifications::class)->user($u->id, $event, (string) Str::uuid(), 'Your password was changed', 'Your NUERS password was changed on '.now('Asia/Manila')->format('d M Y, g:i A').' PHT. All application sessions were signed out. If you did not make this change, request a password reset and contact NUERS support immediately.', 'security');
        app(BirCompliance::class)->audit($event, (string) $u->id, (string) $u->id);
    }

    public function preferences(Request $r)
    {
        $u = app(ApiAccess::class)->require($r);
        if ($r->isMethod('PUT')) {
            $v = $r->validate(['workflow_email' => 'required|boolean', 'support_email' => 'required|boolean', 'operations_email' => 'required|boolean']);
            DB::table('notification_preferences')->updateOrInsert(['user_id' => (string) $u->id], [...$v, 'created_at' => now(), 'updated_at' => now()]);
        }
        $p = DB::table('notification_preferences')->where('user_id', (string) $u->id)->first();

        return response()->json(['email' => $u->email, 'email_verified' => (bool) $u->email_verified_at, 'security_email' => true, 'workflow_email' => (bool) ($p->workflow_email ?? true), 'support_email' => (bool) ($p->support_email ?? true), 'operations_email' => (bool) ($p->operations_email ?? true),
            'recent' => DB::table('notification_outbox')->where('user_id', (string) $u->id)->orderByDesc('created_at')->limit(15)->get(['id', 'event', 'category', 'state', 'created_at'])->map(fn ($row) => $this->dates($row))])->header('Cache-Control', 'private, no-store');
    }

    public function admin(Request $r)
    {
        app(ApiAccess::class)->require($r, ['admin', 'super_admin']);

        return response()->json(['dispatch_enabled' => (bool) config('notifications.dispatch_enabled'), 'catalog' => NotificationEvents::CATALOG, 'runtime' => $this->dates(DB::table('notification_runtime')->where('id', 1)->first()),
            'counts' => DB::table('notification_outbox')->selectRaw('state, COUNT(*) as total')->groupBy('state')->get(),
            'recent' => DB::table('notification_outbox')->orderByDesc('created_at')->limit(30)->get(['id', 'event', 'category', 'state', 'attempts', 'error_code', 'created_at', 'accepted_at'])->map(fn ($row) => $this->dates($row))])->header('Cache-Control', 'private, no-store');
    }

    private function dates(?object $row): ?object
    {
        if (! $row) {
            return null;
        }
        foreach (['created_at', 'accepted_at', 'worker_seen_at', 'activated_at'] as $field) {
            if (! empty($row->$field)) {
                $row->$field = CarbonImmutable::parse($row->$field, config('app.timezone'))->toIso8601String();
            }
        }

        return $row;
    }
}
