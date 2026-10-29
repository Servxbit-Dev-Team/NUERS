<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('esp_accreditations', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('solution_name'); $t->string('solution_version');
            $t->string('configuration_reference')->nullable(); $t->string('status')->default('not_submitted');
            $t->string('certificate_number')->nullable(); $t->date('issued_at')->nullable();
            $t->date('expires_at')->nullable(); $t->date('annual_review_at')->nullable();
            $t->text('evidence_reference')->nullable(); $t->string('verified_by')->nullable();
            $t->timestamp('verified_at')->nullable(); $t->boolean('material_change_pending')->default(false); $t->timestamps();
        });
        Schema::create('esp_accreditation_controls', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('accreditation_id')->index();
            $t->string('category'); $t->string('code'); $t->string('status')->default('pending');
            $t->text('evidence_reference')->nullable(); $t->string('verified_by')->nullable();
            $t->timestamp('verified_at')->nullable(); $t->timestamps();
            $t->unique(['accreditation_id', 'category', 'code'], 'esp_control_scope_unique');
        });
        Schema::create('eis_operational_incidents', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('merchant_id')->nullable()->index();
            $t->string('title'); $t->string('severity')->default('critical'); $t->string('status')->default('open');
            $t->timestamp('detected_at'); $t->timestamp('restored_at')->nullable();
            $t->timestamp('reported_at')->nullable(); $t->text('report_reference')->nullable();
            $t->text('evidence_reference'); $t->string('actor_id'); $t->timestamps();
        });
        Schema::create('eis_audit_head', function (Blueprint $t) { $t->unsignedInteger('id')->primary(); $t->char('last_hash', 64); });
        DB::table('eis_audit_head')->insert(['id' => 1, 'last_hash' => str_repeat('0', 64)]);
        Schema::create('eis_audit_events', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->string('event'); $t->string('subject_id')->nullable()->index();
            $t->string('actor_id'); $t->longText('details'); $t->timestamp('occurred_at', 6);
            $t->char('previous_hash', 64); $t->char('entry_hash', 64)->unique();
        });
        Schema::table('eis_integrations', function (Blueprint $t) {
            $t->uuid('esp_accreditation_id')->nullable(); $t->string('pti_status')->default('pending');
            $t->string('permit_to_issue_number')->nullable(); $t->text('taxpayer_authorization_reference')->nullable();
            $t->timestamp('written_authorization_at')->nullable();
        });
        Schema::table('eis_transmissions', function (Blueprint $t) {
            $t->uuid('incident_id')->nullable()->index(); $t->timestamp('recovery_due_at')->nullable();
            $t->char('document_identity_hash', 64)->nullable();
            $t->unique(['environment', 'document_identity_hash'], 'eis_document_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::table('eis_transmissions', function (Blueprint $t) { $t->dropUnique('eis_document_identity_unique'); $t->dropColumn(['incident_id', 'recovery_due_at', 'document_identity_hash']); });
        Schema::table('eis_integrations', fn (Blueprint $t) => $t->dropColumn(['esp_accreditation_id', 'pti_status', 'permit_to_issue_number', 'taxpayer_authorization_reference', 'written_authorization_at']));
        foreach (['eis_audit_events', 'eis_audit_head', 'eis_operational_incidents', 'esp_accreditation_controls', 'esp_accreditations'] as $table) Schema::dropIfExists($table);
    }
};
