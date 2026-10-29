<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Copy configuration metadata only. No authority, secrets or connectivity are inferred.
        foreach (DB::table('eis_integrations')->get() as $i) {
            $id = (string) Str::uuid();
            DB::table('integration_connections')->insert(['id' => $id, 'request_id' => (string) Str::uuid(), 'name' => $i->merchant_name.' · BIR EIS', 'provider' => 'bir_eis', 'merchant_id' => $i->merchant_id, 'environment' => $i->environment === 'production' ? 'production' : 'certification', 'base_url' => $i->endpoint_base_url, 'api_version' => $i->api_version, 'timeout_seconds' => 30, 'max_retries' => 3, 'status' => 'draft', 'evidence' => 'Existing EIS integration metadata: '.$i->id.'. Provider specification and authorization still require review.', 'reason' => 'Imported configuration metadata for the integration workspace; no connectivity or credential verification inferred.', 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    { /* Preserve reviewed configuration and audit history on rollback. */
    }
};
