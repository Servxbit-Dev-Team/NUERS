<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Allowlisted workflow notifications; audit details and financial documents never enter email bodies. */
class NotificationEvents
{
    public const CATALOG = [
        'security' => ['Account email verification (24 hours)', 'Password changed / reset', 'Password reset link (30 minutes)', 'New browser sign-in', 'Repeated failed sign-in attempts', 'Account removal', 'Account permission changes', 'Google account connected', 'Authenticator enabled', 'Recovery code used', 'API key created, changed or revoked', 'Integration credentials changed'],
        'workflow' => ['Registration verification code (10 minutes)', 'Business registration received', 'Citizen account created / reviewed', 'Business account invitation (48 hours)', 'Business review / activation', 'Taxpayer identity review', 'Document correction / cancellation review', 'Document legal hold / release', 'Permit registration / review / expiry reminders', 'Integration configuration changes', 'Wallet low-balance and funding review notices'],
        'support' => ['Authenticated support request received', 'Support agent reply available', 'Support case status changed'],
        'operations' => ['New onboarding / identity / document review requests', 'SMTP configuration changed', 'ESP accreditation review / certificate changes', 'Critical EIS incident', 'Daily EIS delivery / reconciliation action digest', 'Backup / recovery request and evidence reviews'],
        'customer_copy' => ['Explicit Send copy to client with the same PDF as Invoice Preview'],
    ];

    public function recorded(string $event, ?string $subject, string $actor, array $details, string $key): void
    {
        // Do not add recipient lookups or emails to the real-time ingestion hot path.
        if (! in_array($event, [
            'wallet.adjustment.posted', 'wallet.balance.low', 'wallet.topup.requested', 'wallet.topup.reviewed', 'bcdr.requests.created', 'bcdr.requests.reviewed', 'bcdr.evidence.created', 'bcdr.evidence.reviewed', 'security.mfa.enrolled', 'security.mfa.verified', 'api_key.created', 'api_key.updated', 'api_key.revoked',
            'business_registry.reviewed', 'business_registry.account.activated', 'business.registration.submitted', 'business.profile.review_requested', 'business.verification.document_uploaded', 'business.verification.document_reviewed',
            'identity.claim.requested', 'identity.claim.reviewed', 'document.cancellation.requested', 'document.correction.requested',
            'document.hold.recorded', 'document.hold.released', 'permit.registered', 'permit.reviewed',
            'integration.configuration_saved', 'integration.credential_replaced', 'integration.credential_revoked',
            'integration.certificate_recorded', 'integration.endpoint_saved', 'integration.webhook_saved',
            'eis.delivery.rejected', 'eis.delivery.uncertain', 'eis.delivery.dead_letter', 'configuration.smtp.saved',
            'esp.application.created', 'esp.evidence.submitted', 'esp.review.recorded', 'esp.certificate.recorded',
            'eis.incident.recorded', 'eis.incident.updated', 'merchant_registry.verification_requested', 'merchant_registry.branch_reviewed', 'merchant_registry.terminal_updated',
        ], true) && ! str_starts_with($event, 'document.review.')) {
            return;
        }
        $n = app(PlatformNotifications::class);
        if (! $n->ready()) {
            return;
        }
        if (str_starts_with($event, 'wallet.')) {
            $merchant = (string) $subject;
            if ($event === 'wallet.adjustment.posted') {
                $n->merchant($merchant, $event, $key, 'Wallet adjustment recorded', 'NUERS recorded a manual wallet top-up or withdrawal. Sign in and open Wallet to review the transaction reference and current balance. Contact NUERS support if this was unexpected.', 'operations');
            } elseif ($event === 'wallet.balance.low') {
                $n->merchant($merchant, $event, $key, 'NUERS wallet balance is low', 'Your prepaid wallet reached its low-balance threshold. Open Wallet in NUERS to review the current balance and request funding. Unfunded eligible sales remain on your client until you retry after funding.', 'operations');
            } elseif ($event === 'wallet.topup.requested') {
                $n->merchant($merchant, $event, $key, 'Wallet funding request received', 'Your NUERS funding request is awaiting independent verification. No funds are credited until a Super Admin confirms settlement. Track the decision in Wallet.');
                foreach (DB::table('profiles')->where('role', 'super_admin')->pluck('id') as $id) {
                    $n->user($id, $event, $key, 'Wallet funding awaiting verification', 'A merchant submitted a wallet funding request. Open Wallets & Transaction Pricing in NUERS and independently verify settled funds before recording a decision.', 'operations');
                }
            } else {
                $n->merchant($merchant, $event, $key, 'Wallet funding review completed', 'NUERS recorded a funding review decision. Sign in and open Wallet to review the decision, any review note and the actual ledger balance.');
            }

            return;
        }
        if (str_starts_with($event, 'merchant_registry.')) {
            $merchant = $details['merchant_id'] ?? null;
            if ($event === 'merchant_registry.verification_requested') {
                $n->admins($event, $key, 'Branch verification awaiting review', 'A merchant submitted branch evidence for independent review. Open Registered Businesses → Branch Verification in NUERS. Branch review does not establish BIR authority.');
                $n->merchant($merchant, $event, $key, 'Branch verification requested', 'Your branch evidence is awaiting independent NUERS administrator review. Track the request in Branches / Terminals.');
            } elseif ($event === 'merchant_registry.branch_reviewed') {
                $n->merchant($merchant, $event, $key, 'Branch verification update', 'An independent administrator recorded a branch evidence decision. Sign in to Branches / Terminals to review the status. PTI, PTT and BIR connectivity remain separate.');
            } else {
                $n->merchant($merchant, $event, $key, 'Terminal configuration changed', 'A terminal configuration or branch assignment changed in your NUERS account. Review Branches / Terminals. If this was unexpected, review API access and contact NUERS support.', 'security');
            }

            return;
        }
        if (str_starts_with($event, 'bcdr.')) {
            $n->admins($event, $key, 'Backup and recovery workflow update', 'A NUERS backup / disaster recovery '.$event.' event was recorded. Sign in to Backup & Disaster Recovery to review requests and supporting evidence. No restore or failover is executed by this notification.');

            return;
        }
        $message = 'Sign in to NUERS to review the recorded change and any required action. This notification does not establish BIR approval or transmission acceptance.';
        if (in_array($event, ['security.mfa.enrolled', 'security.mfa.verified'], true)) {
            if ($event === 'security.mfa.verified' && empty($details['recovery_code'])) {
                return;
            }
            $title = $event === 'security.mfa.enrolled' ? 'Authenticator enabled' : 'Recovery code used';
            $n->user($subject, $event, $key, $title, 'A security action was completed on your NUERS account: '.$title.'. If you did not authorize this, change your password and contact NUERS support immediately.', 'security');

            return;
        }
        if (str_starts_with($event, 'api_key.')) {
            $n->merchant($details['merchant_id'] ?? null, $event, $key, 'API access changed', 'An API key was '.substr($event, 8).'. Review API Integration in NUERS. If this was unexpected, revoke access and contact NUERS support.', 'security');

            return;
        }
        if (str_starts_with($event, 'business_registry.') && in_array($event, ['business_registry.reviewed', 'business_registry.account.activated'], true)) {
            $m = DB::table('merchants')->where('id', $subject)->first();
            if ($m) {
                $n->merchant($m->id, $event, $key, 'Business account update', 'Your NUERS business account status is '.str_replace('_', ' ', $m->status).'. '.$message);
            }

            return;
        }
        if ($event === 'business.profile.review_requested') {
            $m = DB::table('merchants')->where('id', $subject)->first();
            if (! $m) {
                return;
            }
            // The persisted submission revision is stable across retries and notification repairs.
            // Keep the existing administrator key so older queued notices are not duplicated.
            $submission = isset($details['submission_revision']) ? $m->id.':'.$details['submission_revision'] : $key;
            $business = 'Business: '.$m->business_name."\n\n";
            $n->merchant($m->id, $event, $submission, 'Business profile submitted for review', $business.'NUERS received your business profile and required documents for review. Authorized NUERS administrators will independently review the evidence. Sign in and open Business Profile to track the decision and any requested changes. Your account remains pending until an administrator approves activation. NUERS account review does not establish BIR approval or enable EIS transmission.', action: $n->url('merchant/profile'));
            $n->admins($event, $submission, 'Business profile ready for review', $business.'A merchant submitted a complete document checklist. Open Registered Businesses, select the business and choose Review / Manage Status to inspect the private evidence before deciding account activation. Uploads and NUERS account review do not grant BIR authority.', $n->url('super-admin/merchants'));

            return;
        }
        if ($event === 'business.verification.document_uploaded') {
            $state = app(MerchantVerificationDocuments::class)->present((string) $subject);
            if ($state['uploaded'] === $state['total']) {
                $n->admins($event, (string) $subject.'|'.now()->format('Y-m-d-H'), 'Business verification files awaiting review', 'A business has uploaded all verification document categories. Open Taxpayer Onboarding in NUERS to review the private evidence. Uploading files does not activate the account.');
            }

            return;
        }
        if ($event === 'business.verification.document_reviewed') {
            $n->merchant($subject, $event, (string) $subject.'|'.($details['decision'] ?? 'pending').'|'.now()->format('Y-m-d-H'), 'Business verification document update', 'NUERS recorded a review decision for your business verification files. Sign in and open Business Profile → Documents to see the status and any requested changes. Account activation is a separate administrator decision.');

            return;
        }
        if ($event === 'business.registration.submitted') {
            $n->user($actor, $event, $key, 'Business registration received', 'NUERS received your business registration. Your application is pending review. Upload any missing verification files from Merchant Dashboard → Business Profile → Documents. Required documents must be reviewed before NUERS account activation. Account review and BIR authority are separate processes.');
            $n->admins($event, $key, 'Business registration awaiting review', 'A new business registration is ready for review in Taxpayer Onboarding.');

            return;
        }
        if (str_starts_with($event, 'identity.claim.')) {
            $row = DB::table('taxpayer_identity_reviews')->where('id', $subject)->first();
            $n->user($row?->user_id, $event, $key, 'Identity review update', 'Your taxpayer identity review is '.($row?->status ?? 'pending').'. '.$message);
            if ($event === 'identity.claim.requested') {
                $n->admins($event, $key, 'Identity review awaiting action', 'A taxpayer identity claim requires an independent review in NUERS.');
            }

            return;
        }
        if (preg_match('/^document\.(cancellation|correction)\.requested$/', $event) || str_starts_with($event, 'document.review.')) {
            $row = DB::table('document_review_requests')->where('id', $subject)->first();
            if ($row) {
                $n->merchant($row->merchant_id, $event, $key, 'Document review update', 'A document '.$row->kind.' review is '.str_replace('_', ' ', $row->status).'. '.$message);
            }
            if (str_ends_with($event, '.requested')) {
                $n->admins($event, $key, 'Document review awaiting action', 'A correction or cancellation request is awaiting review in NUERS.');
            }

            return;
        }
        if (in_array($event, ['document.hold.recorded', 'document.hold.released'], true)) {
            $row = DB::table('document_legal_holds')->where('id', $subject)->first();
            if ($row) {
                $n->merchant($row->merchant_id, $event, $key, 'Document preservation update', 'A document preservation control was '.(str_ends_with($event, 'released') ? 'released' : 'recorded').'. '.$message);
            }

            return;
        }
        if (in_array($event, ['permit.registered', 'permit.reviewed'], true)) {
            $row = DB::table('taxpayer_authorizations')->where('id', $subject)->first();
            if ($row) {
                $n->merchant($row->merchant_id, $event, $key, 'Taxpayer authority record updated', 'A '.$row->kind.' authority record has been updated in NUERS. '.$message);
            }

            return;
        }
        if (str_starts_with($event, 'integration.') && ! in_array($event, ['integration.logs_exported', 'integration.diagnostics'], true)) {
            $category = str_contains($event, 'credential') ? 'security' : 'workflow';
            $connection = DB::table('integration_connections')->where('id', $subject)->first();
            if ($connection?->merchant_id) {
                $n->merchant($connection->merchant_id, $event, $key, 'Integration configuration changed', $message, $category);
            } else {
                $n->user($actor, $event, $key, 'Integration configuration changed', $message, $category);
            }

            return;
        }
        if (in_array($event, ['eis.delivery.rejected', 'eis.delivery.uncertain', 'eis.delivery.dead_letter'], true)) {
            $job = DB::table('eis_delivery_jobs')->where('id', $subject)->first();
            if ($job) {
                $n->merchant($job->merchant_id, 'eis.delivery.action', $job->merchant_id.'|'.now('Asia/Manila')->format('Y-m-d-H'), 'EIS delivery requires review', 'One or more document delivery attempts require review. Open BIR Submission Status in NUERS to inspect the recorded evidence. Do not replay an uncertain submission without authentic inquiry.', 'operations');
            }

            return;
        }
        if (in_array($event, ['configuration.smtp.saved', 'esp.application.created', 'esp.evidence.submitted', 'esp.review.recorded', 'esp.certificate.recorded', 'eis.incident.recorded', 'eis.incident.updated'], true)) {
            $n->admins($event, $key, 'Platform configuration or compliance update', 'A platform change was recorded: '.str_replace(['.', '_'], ' ', $event).'. '.$message);
        }
    }

    /** Aggregate current exceptions. Never send historic backlog or one email per high-volume transaction. */
    public function reconcile(): void
    {
        $n = app(PlatformNotifications::class);
        if (! $n->ready()) {
            return;
        }
        $activation = DB::table('notification_runtime')->where('id', 1)->value('activated_at');
        $daily = now('Asia/Manila')->toDateString();
        $q = DB::table('operational_notifications')->where('created_at', '>=', $activation)->where('created_at', '>=', now()->subDay());
        $total = (clone $q)->count();
        if ($total) {
            $n->admins('operations.daily_digest', $daily, 'EIS action summary', "There are $total recent operational reminders in NUERS. Review delivery exceptions, overdue reconciliation and incident reporting in the operations workspace. Email delivery does not transmit documents to BIR.");
            foreach ((clone $q)->whereNotNull('merchant_id')->select('merchant_id')->selectRaw('COUNT(*) as total')->groupBy('merchant_id')->get() as $m) {
                $n->merchant($m->merchant_id, 'operations.merchant_digest', $daily, 'EIS action required', "There are $m->total recent operational reminders for your business. Review BIR Submission Status and resolve the recorded evidence or delivery issues.", 'operations');
            }
        }
        foreach (DB::table('eis_delivery_jobs')->where('updated_at', '>=', $activation)->whereIn('state', ['rejected', 'uncertain', 'dead_letter'])->select('merchant_id')->selectRaw('COUNT(*) as total')->groupBy('merchant_id')->get() as $m) {
            $n->merchant($m->merchant_id, 'operations.delivery_digest', $daily, 'EIS delivery action summary', "There are $m->total delivery records requiring review. Open BIR Submission Status and review the recorded evidence before taking action.", 'operations');
        }
        foreach (DB::table('taxpayer_authorizations')->whereNotNull('valid_until')->whereBetween('valid_until', [now()->toDateString(), now()->addDays(30)->toDateString()])->whereIn('status', ['active'])->cursor() as $p) {
            $n->merchant($p->merchant_id, 'permit.expiring', $p->id.'|'.substr($daily, 0, 7), 'Taxpayer authority expiry reminder', 'A '.$p->kind.' authority record expires on '.$p->valid_until.'. Review the supporting authority and renewal requirements in NUERS.');
        }
    }
}
