<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Legacy MariaDB TIMESTAMP defaults must not renew a security deadline on update.
        foreach (['infra_actions', 'infra_proofs'] as $table) {
            DB::statement("ALTER TABLE `$table` MODIFY `expires_at` DATETIME NOT NULL");
        }
    }

    public function down(): void
    {
        // Restoring implicit ON UPDATE behavior would weaken expiration guarantees.
    }
};
