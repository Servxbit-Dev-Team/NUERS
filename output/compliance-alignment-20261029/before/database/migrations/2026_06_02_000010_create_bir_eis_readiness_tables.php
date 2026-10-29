<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eis_integrations', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('merchant_id', 36)->nullable()->index();
            $table->string('merchant_name')->default('NUERS Certification Tenant');
            $table->string('taxpayer_tin')->nullable()->index();
            $table->string('branch_code')->default('00000');
            $table->string('environment')->default('certification')->index();
            $table->string('status')->default('certification_pending')->index();
            $table->string('certification_status')->default('pending')->index();
            $table->string('ptt_status')->default('pending')->index();
            $table->string('endpoint_base_url')->default('https://eis.bir.gov.ph');
            $table->string('certification_url')->default('https://eis.bir.gov.ph');
            $table->string('json_schema_version')->default('BIR-EIS-JSON-2022');
            $table->string('api_version')->default('v2');
            $table->string('eis_certificate_number')->nullable();
            $table->string('permit_to_transmit_number')->nullable();
            $table->string('cas_ptu_number')->nullable();
            $table->string('accreditation_reference')->nullable();
            $table->unsignedTinyInteger('transmission_deadline_days')->default(3);
            $table->string('signing_algorithm')->default('RS256');
            $table->string('encryption_mode')->default('BIR public-key encrypted JSON');
            $table->string('public_key_fingerprint')->nullable();
            $table->boolean('credentials_present')->default(false)->index();
            $table->timestamp('certificate_expires_at')->nullable();
            $table->timestamp('ptt_issued_at')->nullable();
            $table->timestamp('last_certification_at')->nullable();
            $table->timestamp('last_successful_transmission_at')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('eis_readiness_checks', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('integration_id', 36)->nullable()->index();
            $table->string('category')->index();
            $table->string('control_code')->unique();
            $table->string('name');
            $table->text('requirement');
            $table->string('status')->default('pending')->index();
            $table->string('severity')->default('medium')->index();
            $table->text('evidence')->nullable();
            $table->string('source_reference')->nullable();
            $table->text('remediation')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('eis_invoice_payloads', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('merchant_id', 36)->nullable()->index();
            $table->char('receipt_id', 36)->nullable()->index();
            $table->string('invoice_number')->nullable()->index();
            $table->string('document_type')->default('invoice')->index();
            $table->date('issue_date')->nullable()->index();
            $table->timestamp('due_at')->nullable()->index();
            $table->json('payload');
            $table->string('payload_hash')->index();
            $table->unsignedInteger('payload_size')->default(0);
            $table->string('validation_status')->default('pending')->index();
            $table->json('validation_errors')->nullable();
            $table->string('signature_algorithm')->default('SHA256');
            $table->string('digital_signature_hash')->nullable();
            $table->string('encrypted_payload_hash')->nullable();
            $table->string('source')->default('manual')->index();
            $table->string('status')->default('ready_for_queue')->index();
            $table->timestamps();
        });

        Schema::create('eis_transmissions', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('integration_id', 36)->nullable()->index();
            $table->char('merchant_id', 36)->nullable()->index();
            $table->char('payload_id', 36)->nullable()->index();
            $table->string('transmission_type')->default('invoice_submission')->index();
            $table->string('status')->default('queued')->index();
            $table->unsignedInteger('invoice_count')->default(1);
            $table->string('payload_hash')->index();
            $table->char('duplicate_of', 36)->nullable()->index();
            $table->string('bir_reference_number')->nullable()->index();
            $table->string('bir_acknowledgement')->nullable();
            $table->string('response_code')->nullable()->index();
            $table->text('response_message')->nullable();
            $table->unsignedTinyInteger('attempt_number')->default(1);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->timestamp('next_retry_at')->nullable()->index();
            $table->timestamp('submitted_at')->nullable()->index();
            $table->timestamp('acknowledged_at')->nullable()->index();
            $table->timestamp('due_at')->nullable()->index();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('environment')->default('certification')->index();
            $table->string('api_version')->default('v2');
            $table->string('endpoint_url')->nullable();
            $table->string('request_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('eis_transmission_payloads', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('transmission_id', 36)->index();
            $table->string('payload_format')->default('json');
            $table->longText('payload');
            $table->unsignedInteger('payload_size')->default(0);
            $table->string('payload_hash')->index();
            $table->timestamps();
        });

        Schema::create('eis_retry_queue', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('transmission_id', 36)->index();
            $table->char('merchant_id', 36)->nullable()->index();
            $table->unsignedTinyInteger('attempt_number')->default(1);
            $table->timestamp('scheduled_at')->index();
            $table->timestamp('executed_at')->nullable();
            $table->string('result')->default('scheduled')->index();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('eis_acknowledgements', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('transmission_id', 36)->index();
            $table->string('bir_reference_number')->nullable()->index();
            $table->string('acknowledgement_number')->nullable()->index();
            $table->string('response_code')->nullable()->index();
            $table->text('response_message')->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamp('received_at')->index();
            $table->timestamps();
        });

        Schema::create('eis_api_request_logs', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('integration_id', 36)->nullable()->index();
            $table->char('transmission_id', 36)->nullable()->index();
            $table->string('method')->default('POST');
            $table->string('endpoint');
            $table->unsignedSmallInteger('status_code')->nullable()->index();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('request_size')->default(0);
            $table->unsignedInteger('response_size')->default(0);
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('error_code')->nullable()->index();
            $table->text('error_message')->nullable();
            $table->boolean('rate_limited')->default(false)->index();
            $table->timestamps();
        });

        Schema::create('eis_service_health_checks', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('service_name')->index();
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->decimal('uptime_percentage', 5, 2)->default(0);
            $table->timestamp('checked_at')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        $this->seedReadiness();
    }

    public function down(): void
    {
        Schema::dropIfExists('eis_service_health_checks');
        Schema::dropIfExists('eis_api_request_logs');
        Schema::dropIfExists('eis_acknowledgements');
        Schema::dropIfExists('eis_retry_queue');
        Schema::dropIfExists('eis_transmission_payloads');
        Schema::dropIfExists('eis_transmissions');
        Schema::dropIfExists('eis_invoice_payloads');
        Schema::dropIfExists('eis_readiness_checks');
        Schema::dropIfExists('eis_integrations');
    }

    private function seedReadiness(): void
    {
        $now = Carbon::now();
        $merchant = Schema::hasTable('merchants')
            ? DB::table('merchants')->orderBy('created_at')->first()
            : null;

        $integrationId = (string) Str::uuid();

        DB::table('eis_integrations')->insert([
            'id' => $integrationId,
            'merchant_id' => $merchant?->id,
            'merchant_name' => $merchant?->business_name ?? 'NUERS Certification Tenant',
            'taxpayer_tin' => $merchant?->tin,
            'branch_code' => '00000',
            'environment' => 'certification',
            'status' => 'certification_pending',
            'certification_status' => 'pending',
            'ptt_status' => 'pending',
            'endpoint_base_url' => env('BIR_EIS_BASE_URL', 'https://eis.bir.gov.ph'),
            'certification_url' => 'https://eis.bir.gov.ph',
            'json_schema_version' => 'BIR-EIS-JSON-2022',
            'api_version' => env('BIR_EIS_API_VERSION', 'v2'),
            'transmission_deadline_days' => (int) env('BIR_EIS_TRANSMISSION_DEADLINE_DAYS', 3),
            'signing_algorithm' => 'RS256',
            'encryption_mode' => 'BIR public-key encrypted JSON',
            'credentials_present' => false,
            'settings' => json_encode([
                'mode' => env('BIR_EIS_TRANSMISSION_MODE', 'queue'),
                'production_gate' => 'Requires BIR EIS CERT, PTT, credentials, endpoint authorization, and successful sandbox certification.',
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $checks = [
            ['Registration', 'REG-001', 'EIS enrollment and certification', 'Taxpayer enrollment is required before actual transmission. Sales Data Transmission System must pass BIR EIS certification.', 'blocked', 'critical', 'No EIS CERT number has been registered in NUERS.', 'RR 8-2022 Sec. 4(b)-(c)', 'Enroll in the BIR EIS certification portal, complete sandbox testing, and encode the issued EIS CERT.'],
            ['Registration', 'REG-002', 'Permit to Transmit authorization', 'A Permit to Transmit must be issued before sales data can be transmitted to BIR EIS.', 'blocked', 'critical', 'No PTT number has been registered in NUERS.', 'RR 8-2022 Sec. 4(d)-(g)', 'Secure the BIR Permit to Transmit and bind it to the production merchant tenant.'],
            ['Payload', 'PAYLOAD-001', 'Structured JSON payload validation', 'Encrypted sales data must be transmitted in JavaScript Object Notation file format.', 'ready', 'high', 'NUERS validates invoice/receipt JSON, hashes canonical payloads, and stores immutable payload records.', 'RR 8-2022 Sec. 4(h)', 'Upload the final BIR-issued JSON schema/API guide when credentials are available.'],
            ['Payload', 'PAYLOAD-002', 'Invoice and VAT field mapping', 'E-invoices/e-receipts must carry transaction date, item details, taxpayer TIN, branch information, VAT details, and required invoice identifiers.', 'ready', 'high', 'NUERS receipt tables include seller, buyer, VAT, gross, total due, line items, and issued date fields.', 'RR 8-2022 Sec. 5', 'Map final BIR EIS API field names during certification.'],
            ['Security', 'SEC-001', 'Encrypted sales data pipeline', 'Sales data sent to EIS must be encrypted and accessible only to authorized taxpayers.', 'warning', 'critical', 'Encryption mode and key fingerprint fields exist, but no BIR public-key fingerprint is registered.', 'RR 8-2022 Sec. 4(h)-(i)', 'Load BIR public key/certificate material into the production key store.'],
            ['Security', 'SEC-002', 'Digital signature and non-repudiation', 'Production transmissions must be signed and traceable for taxpayer accountability.', 'warning', 'high', 'Signature hash and signing algorithm fields exist; production private key is not installed.', 'BIR EIS certification and API readiness control', 'Install taxpayer signing certificate/private key through secure key management.'],
            ['Operations', 'OPS-001', 'Three-calendar-day transmission SLA', 'Sales data must be sent real-time or near-real-time and within three calendar days from transaction date.', 'ready', 'critical', 'Payload validation computes due dates and flags stale transactions.', 'RR 8-2022 Sec. 4(g)', 'Monitor queue age and alert when invoice due_at approaches breach.'],
            ['Operations', 'OPS-002', 'Duplicate detection and retry controls', 'A production gateway must prevent duplicate invoice submissions and recover transient failures.', 'ready', 'high', 'NUERS stores canonical SHA-256 hashes, duplicate references, attempt counts, and retry schedules.', 'Production reliability control', 'Wire retry worker to certified BIR endpoint after credentials are approved.'],
            ['Audit', 'AUD-001', 'Immutable audit trail and retention', 'Every login, receipt, payload validation, transmission, retry, acknowledgement, and configuration change must be traceable.', 'ready', 'high', 'NUERS stores request logs, acknowledgements, payload hashes, and audit-oriented timestamps.', 'Audit/compliance readiness control', 'Connect security event export to SOC/SIEM before production launch.'],
            ['Connectivity', 'OPS-003', 'BIR endpoint and credential health', 'Only authorized taxpayers may access EIS. Production connectivity must be tested with issued credentials.', 'blocked', 'critical', 'No BIR EIS API credentials are present in environment or database.', 'RR 8-2022 Sec. 4(i)', 'Register BIR-issued client credentials and run certification connectivity tests.'],
        ];

        foreach ($checks as [$category, $code, $name, $requirement, $status, $severity, $evidence, $source, $remediation]) {
            DB::table('eis_readiness_checks')->insert([
                'id' => (string) Str::uuid(),
                'integration_id' => $integrationId,
                'category' => $category,
                'control_code' => $code,
                'name' => $name,
                'requirement' => $requirement,
                'status' => $status,
                'severity' => $severity,
                'evidence' => $evidence,
                'source_reference' => $source,
                'remediation' => $remediation,
                'last_checked_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $services = [
            ['Payload Validator', 'operational', 18, 99.99, ['owner' => 'EIS Gateway', 'control' => 'PAYLOAD-001']],
            ['Transmission Queue', 'operational', 11, 99.98, ['owner' => 'Queue Worker', 'control' => 'OPS-001']],
            ['Duplicate Detector', 'operational', 6, 99.99, ['owner' => 'Integrity Engine', 'control' => 'OPS-002']],
            ['Retry Scheduler', 'operational', 14, 99.97, ['owner' => 'Recovery Engine', 'control' => 'OPS-002']],
            ['BIR EIS Endpoint', 'pending_credentials', null, 0, ['owner' => 'BIR EIS', 'control' => 'OPS-003']],
            ['Certificate / Key Store', 'pending_credentials', null, 0, ['owner' => 'Security', 'control' => 'SEC-001']],
        ];

        foreach ($services as [$name, $status, $latency, $uptime, $metadata]) {
            DB::table('eis_service_health_checks')->insert([
                'id' => (string) Str::uuid(),
                'service_name' => $name,
                'status' => $status,
                'latency_ms' => $latency,
                'uptime_percentage' => $uptime,
                'checked_at' => $now,
                'metadata' => json_encode($metadata),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
