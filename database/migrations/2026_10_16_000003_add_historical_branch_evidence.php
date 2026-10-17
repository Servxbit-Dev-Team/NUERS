<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eis_document_branch_reviews', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('document_source', 20);
            $t->string('document_id', 80);
            $t->string('branch_code', 5);
            $t->string('source_fingerprint', 64);
            $t->text('evidence_reference');
            $t->string('reviewed_by');
            $t->timestamp('reviewed_at');
            $t->unsignedInteger('revision');
            $t->unique(['document_source', 'document_id', 'revision'], 'eis_branch_review_revision_unique');
        });
        Schema::table('eis_document_outbox', function (Blueprint $t) {
            $t->unsignedInteger('source_review_revision')->default(0);
            $t->dropUnique('eis_outbox_source_schema_unique');
            $t->unique(['document_source', 'document_id', 'internal_schema', 'source_review_revision'], 'eis_outbox_review_version_unique');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve historical evidence and preparation versions; use a reviewed forward migration.');
    }
};
