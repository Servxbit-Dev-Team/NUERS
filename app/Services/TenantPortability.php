<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Allowlisted regulated records, relationally scoped; credentials are intentionally excluded. */
class TenantPortability
{
    public function queries(string $merchant): array
    {
        $queries = [];
        foreach (['merchants', 'merchant_transactions', 'transaction_receipts', 'business_invoices', 'business_invoice_items', 'merchant_expenses', 'eis_invoice_payloads', 'eis_transmissions', 'eis_retry_queue', 'eis_document_outbox', 'eis_reporting_obligations', 'integration_inbox', 'integration_source_profiles', 'invoice_series', 'invoice_lifecycle_events', 'document_review_requests', 'document_legal_holds', 'eis_delivery_jobs', 'operational_notifications', 'webhook_deliveries', 'taxpayer_authorizations', 'eis_operational_incidents'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $queries[$table] = DB::table($table)->where($table === 'merchants' ? 'id' : 'merchant_id', $merchant);
        }
        foreach (['merchant_customers', 'merchant_customer_documents', 'merchant_customer_history', 'merchant_customer_imports', 'merchant_branches', 'merchant_terminals', 'merchant_branch_documents', 'merchant_branch_events', 'merchant_verification_documents', 'merchant_wallets', 'merchant_wallet_entries', 'merchant_wallet_topups', 'merchant_wallet_adjustments'] as $table) {
            if (Schema::hasTable($table)) {
                $columns = array_values(array_diff(Schema::getColumnListing($table), ['request_hash', 'identity_hash', 'request_id', 'name_hash', 'tin_hash', 'email_hash', 'phone_hash', 'private_path', 'payment_reference_hash', 'payment_credit_hash', 'settlement_hash']));
                $queries[$table] = DB::table($table)->where('merchant_id', $merchant)->select($columns);
                if ($table === 'merchant_wallets') {
                    $queries[$table]->selectRaw('merchant_id as id');
                }
            }
        }
        if (Schema::hasTable('eis_document_branch_reviews')) {
            $queries['eis_document_branch_reviews'] = DB::table('eis_document_branch_reviews')->where(function ($q) use ($merchant) {
                $q->where(fn ($q) => $q->where('document_source', 'invoice')->whereIn('document_id', DB::table('business_invoices')->where('merchant_id', $merchant)->select('id')))
                    ->orWhere(fn ($q) => $q->where('document_source', 'receipt')->whereIn('document_id', DB::table('transaction_receipts')->where('merchant_id', $merchant)->select('id')));
            });
        }
        $transmissions = DB::table('eis_transmissions')->where('merchant_id', $merchant)->select('id');
        foreach (['eis_acknowledgements', 'eis_transmission_payloads'] as $table) {
            if (Schema::hasTable($table)) {
                $queries[$table] = DB::table($table)->whereIn('transmission_id', clone $transmissions);
            }
        }
        $queries['eis_delivery_attempts'] = DB::table('eis_delivery_attempts')->whereIn('job_id', DB::table('eis_delivery_jobs')->where('merchant_id', $merchant)->select('id'));
        $queries['taxpayer_devices'] = DB::table('taxpayer_devices')->whereIn('authorization_id', DB::table('taxpayer_authorizations')->where('merchant_id', $merchant)->select('id'));
        $queries['taxpayer_connection_events'] = DB::table('taxpayer_connection_events')->whereIn('device_id', (clone $queries['taxpayer_devices'])->select('id'));
        $ids = DB::table('business_invoices')->where('merchant_id', $merchant)->select('id')->union(DB::table('transaction_receipts')->where('merchant_id', $merchant)->select('id'));
        $queries['reconciliation_reviews'] = DB::table('reconciliation_reviews')->whereIn('transaction_id', $ids);
        if (Schema::hasTable('transaction_receipt_items')) {
            $queries['transaction_receipt_items'] = DB::table('transaction_receipt_items')->whereIn('receipt_id', DB::table('transaction_receipts')->where('merchant_id', $merchant)->select('id'));
        }
        $subjects = DB::table('merchants')->where('id', $merchant)->select('id');
        foreach (['merchant_transactions', 'transaction_receipts', 'business_invoices', 'eis_document_outbox', 'eis_delivery_jobs', 'document_review_requests', 'document_legal_holds', 'taxpayer_authorizations', 'integration_inbox', 'merchant_expenses'] as $table) {
            $subjects->union(DB::table($table)->where('merchant_id', $merchant)->select('id'));
        }
        foreach (['merchant_customers', 'merchant_customer_imports', 'merchant_branches', 'merchant_terminals', 'merchant_branch_documents', 'merchant_branch_events', 'merchant_verification_documents', 'merchant_wallet_entries', 'merchant_wallet_topups', 'merchant_wallet_adjustments'] as $table) {
            if (Schema::hasTable($table)) {
                $subjects->union(DB::table($table)->where('merchant_id', $merchant)->select('id'));
            }
        }
        $queries['eis_audit_events'] = DB::table('eis_audit_events')->whereIn('subject_id', clone $subjects);
        $queries['audit_events'] = DB::table('audit_events')->whereIn('auditable_id', clone $subjects);
        foreach (['integration_connections', 'eis_integrations'] as $table) {
            $columns = array_values(array_diff(Schema::getColumnListing($table), ['encrypted_secret', 'settings', 'public_key_fingerprint', 'secret_hint']));
            $queries[$table] = DB::table($table)->where('merchant_id', $merchant)->select($columns);
        }
        $connectionIds = DB::table('integration_connections')->where('merchant_id', $merchant)->select('id');
        $queries['integration_endpoints'] = DB::table('integration_endpoints')->whereIn('connection_id', clone $connectionIds);
        $queries['integration_webhooks'] = DB::table('integration_webhooks')->whereIn('connection_id', clone $connectionIds)->select(array_values(array_diff(Schema::getColumnListing('integration_webhooks'), ['encrypted_secret'])));
        $queries['eis_api_request_logs'] = DB::table('eis_api_request_logs')->whereIn('integration_id', DB::table('eis_integrations')->where('merchant_id', $merchant)->select('id'));
        $queries['merchant_api_request_logs'] = DB::table('merchant_api_request_logs')->where('merchant_id', $merchant);

        return $queries;
    }
}
