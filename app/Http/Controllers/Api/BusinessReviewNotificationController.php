<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\{ApiAccess, BirCompliance, BusinessReviewNotifications, PlatformMail, PlatformNotifications};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt, DB};
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BusinessReviewNotificationController extends Controller
{
    private function actor(Request $r): object
    {
        return app(ApiAccess::class)->require($r, ['admin', 'super_admin']);
    }

    private function enabled(array $state): bool
    {
        return (bool) config('notifications.dispatch_enabled') && ! empty(app(PlatformMail::class)->settings()['enabled']) && (bool) (DB::table('notification_preferences')->where('user_id', $state['owner_id'])->value('workflow_email') ?? true);
    }

    public function index(Request $r, string $merchant)
    {
        $this->actor($r);
        $state = app(BusinessReviewNotifications::class)->state($merchant);

        return response()->json([...$state, 'delivery_enabled' => $this->enabled($state), 'history' => app(BusinessReviewNotifications::class)->history($merchant), 'notice' => 'Email uses the linked merchant sign-in address and saved decisions. Delivery respects workflow email preferences. SMTP acceptance does not confirm inbox placement.'])->header('Cache-Control', 'private, no-store');
    }

    public function preview(Request $r, string $merchant)
    {
        $u = $this->actor($r);
        $v = $r->validate(['kind' => ['required', Rule::in(array_keys(BusinessReviewNotifications::KINDS))], 'message' => 'nullable|string|max:2000']);
        $s = app(BusinessReviewNotifications::class)->state($merchant);
        $draft = $s['drafts'][$v['kind']];
        abort_unless($draft['eligible'], 409, $draft['blocker']);
        abort_if((string) $u->id === $s['owner_id'], 403, 'An independent administrator must send review notices.');
        $body = $draft['body'];
        if (trim($v['message'] ?? '') !== '') {
            $body .= "\n\nAdditional message from NUERS:\n".trim($v['message']);
        }
        $snapshot = ['merchant_id' => $merchant, 'kind' => $v['kind'], 'fingerprint' => $s['fingerprint'], 'recipient' => $s['recipient'], 'subject' => $draft['subject'], 'body' => $body, 'owner_id' => $s['owner_id'], 'action_url' => app(PlatformNotifications::class)->url('merchant/profile')];
        $token = Crypt::encryptString(json_encode(['actor_id' => (string) $u->id, 'session' => hash('sha256', $r->bearerToken()), 'expires' => now()->addMinutes(10)->timestamp, 'snapshot' => $snapshot], JSON_THROW_ON_ERROR));

        return response()->json([...$snapshot, 'preview_token' => $token, 'expires_at' => now()->addMinutes(10)->toIso8601String()])->header('Cache-Control', 'private, no-store');
    }

    public function send(Request $r, string $merchant)
    {
        $u = $this->actor($r);
        $v = $r->validate(['request_id' => 'required|uuid', 'preview_token' => 'required|string|max:32000', 'confirmed' => 'required|accepted', 'resend' => 'sometimes|boolean', 'resend_reason' => 'nullable|string|min:10|max:500']);
        try {
            $proof = json_decode(Crypt::decryptString($v['preview_token']), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(422, 'Preview this notification again before sending.');
        }
        abort_unless(($proof['actor_id'] ?? null) === (string) $u->id && hash_equals($proof['session'] ?? '', hash('sha256', $r->bearerToken())) && ($proof['snapshot']['merchant_id'] ?? null) === $merchant, 403, 'This preview belongs to another administrator, session or business.');
        $snapshot = $proof['snapshot'];
        $hash = hash('sha256', json_encode([$snapshot, (bool) ($v['resend'] ?? false), trim($v['resend_reason'] ?? '')], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($r, $merchant, $u, $v, $proof, $snapshot, $hash) {
            abort_unless(DB::table('merchants')->where('id', $merchant)->lockForUpdate()->first(), 404);
            $existing = DB::table('business_review_notifications')->where('merchant_id', $merchant)->where('request_id', $v['request_id'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'This request ID was already used for another message.');
                return $this->result($existing, true);
            }
            abort_unless(($proof['expires'] ?? 0) > now()->timestamp, 409, 'This preview expired. Review a new preview before sending.');
            $state = app(BusinessReviewNotifications::class)->state($merchant);
            abort_unless(hash_equals($state['fingerprint'], $snapshot['fingerprint']) && ($state['drafts'][$snapshot['kind']]['eligible'] ?? false), 409, 'The business decision, documents or recipient changed. Refresh and preview again.');
            abort_if((string) $u->id === $state['owner_id'], 403, 'An independent administrator must send review notices.');
            abort_unless($this->enabled($state), 409, 'Workflow email is disabled for this recipient, or SMTP delivery is paused.');
            $last = DB::table('business_review_notifications as n')->join('notification_outbox as o', 'o.id', '=', 'n.outbox_id')->where('n.merchant_id', $merchant)->where('n.kind', $snapshot['kind'])->where('n.decision_fingerprint', $snapshot['fingerprint'])->orderByDesc('n.created_at')->orderByDesc('n.id')->select(['n.*', 'o.state'])->first();
            if ($last) {
                if (in_array($last->state, ['queued', 'preparing', 'sending'], true)) {
                    return $this->result($last, true);
                }
                abort_if($last->state === 'uncertain', 409, 'The previous SMTP outcome is uncertain. Review delivery operations before sending again.');
                if (empty($v['resend'])) {
                    return $this->result($last, true);
                }
                abort_unless(strlen(trim($v['resend_reason'] ?? '')) >= 10, 422, 'Record a reason before intentionally resending this notice.');
            }
            $recent = DB::table('business_review_notifications')->where('merchant_id', $merchant)->where('created_at', '>', now()->subMinutes(5))->exists();
            abort_if($recent, 429, 'A review email was queued for this business recently. Wait five minutes and check delivery history before sending another.');
            abort_if(DB::table('business_review_notifications')->where('merchant_id', $merchant)->where('created_at', '>', now()->subHour())->count() >= 5, 429, 'The review email limit for this business is five per hour. Check its delivery history.');
            $id = (string) Str::uuid();
            $snapshot['resend_reason'] = $last ? trim($v['resend_reason']) : null;
            $outbox = app(PlatformNotifications::class)->queue($state['recipient'], 'business.review.manual', $id, $snapshot['subject'], $snapshot['body'], 'workflow', $state['owner_id'], $merchant, $snapshot['action_url'], context: ['review_notification_id' => $id]);
            abort_unless($outbox, 503, 'The notification could not be queued. No email was sent.');
            DB::table('business_review_notifications')->insert(['id' => $id, 'merchant_id' => $merchant, 'actor_id' => (string) $u->id, 'request_id' => $v['request_id'], 'kind' => $snapshot['kind'], 'decision_fingerprint' => $snapshot['fingerprint'], 'request_hash' => $hash, 'outbox_id' => $outbox, 'encrypted_snapshot' => Crypt::encryptString(json_encode($snapshot, JSON_THROW_ON_ERROR)), 'created_at' => now()]);
            app(BirCompliance::class)->audit('business.review.notification_queued', $merchant, (string) $u->id, ['notification_id' => $id, 'kind' => $snapshot['kind'], 'outbox_id' => $outbox, 'resend' => (bool) $last]);

            return $this->result(DB::table('business_review_notifications')->where('id', $id)->first(), false);
        }, 3);
    }

    private function result(object $record, bool $duplicate)
    {
        $state = DB::table('notification_outbox')->where('id', $record->outbox_id)->value('state');

        return response()->json(['id' => $record->id, 'state' => $state, 'duplicate' => $duplicate, 'message' => $duplicate ? 'This decision already has a notification record. No duplicate email was queued.' : 'Review email queued. Check delivery history for the SMTP result.'], $state === 'queued' ? 202 : 200)->header('Cache-Control', 'private, no-store');
    }
}
