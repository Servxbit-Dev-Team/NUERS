<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Queue once inside the accepted sale transaction. SMTP never runs on the intake request. */
class ApiCustomerReceipt
{
    public function queue(object $merchant, string $inboxId, string $receiptId, ?string $email): array
    {
        if (! $email) {
            return $this->status((string) $merchant->id, $receiptId);
        }
        $id = (string) Str::uuid();
        DB::table('document_email_deliveries')->insert([
            'id' => $id, 'request_id' => $inboxId, 'merchant_id' => $merchant->id,
            'document_source' => 'receipt', 'document_id' => $receiptId, 'actor_id' => (string) $merchant->merchant_account_id,
            'encrypted_recipient' => Crypt::encryptString($email), 'request_hash' => hash('sha256', 'receipt|'.$receiptId.'|'.$email),
            'purpose' => 'api_customer_receipt', 'state' => 'queued', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $outbox = app(PlatformNotifications::class)->queue(
            $email,
            'document.customer_copy',
            $id,
            'Your e-receipt from '.$merchant->business_name,
            'Thank you for your purchase from '.$merchant->business_name.'. Your electronic document copy is attached as a PDF. Keep this copy for your records.',
            'customer_copy',
            merchantId: (string) $merchant->id,
            context: ['delivery_id' => $id]
        );
        DB::table('document_email_deliveries')->where('id', $id)->update([
            'outbox_id' => $outbox, 'state' => $outbox ? 'queued' : 'failed', 'error_code' => $outbox ? null : 'queue_unavailable',
        ]);

        return $this->status((string) $merchant->id, $receiptId);
    }

    public function status(string $merchantId, string $receiptId): array
    {
        $delivery = DB::table('document_email_deliveries')->where('merchant_id', $merchantId)
            ->where('document_source', 'receipt')->where('document_id', $receiptId)->where('purpose', 'api_customer_receipt')->first();
        $state = $delivery?->state ?? 'not_requested';
        $reason = match ($state) {
            'queued', 'preparing', 'sending' => 'Customer receipt is queued for email processing.',
            'smtp_accepted' => 'The email server accepted the receipt. Inbox delivery is not confirmed.',
            'uncertain' => 'Email delivery could not be confirmed. Contact support before resending to avoid duplicates.',
            'failed' => 'Receipt email could not be prepared or queued. Contact support or send a copy from the merchant dashboard.',
            'expired' => 'Receipt email expired before sending. Send a copy from the merchant dashboard.',
            'cancelled' => 'Receipt email was cancelled because the account or document is no longer eligible.',
            default => 'No customer email was supplied for automatic delivery.',
        };

        return ['delivery_id' => $delivery?->id, 'status' => $state, 'reason' => $reason];
    }

    public function sandbox(): array
    {
        return ['delivery_id' => null, 'status' => 'suppressed_sandbox', 'reason' => 'Sandbox never sends customer receipt emails.'];
    }
}
