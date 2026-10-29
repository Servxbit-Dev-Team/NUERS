<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eis_document_outbox', function (Blueprint $t) {
            $t->dropUnique(['document_source', 'document_id']);
            $t->unique(['document_source', 'document_id', 'internal_schema'], 'eis_outbox_source_schema_unique');
        });
    }

    public function down(): void
    {
        // Versioned evidence cannot be collapsed without a reviewed archive; rollback deliberately preserves it.
        throw new RuntimeException('Preserve versioned EIS preparation evidence; use a reviewed forward migration.');
    }
};
