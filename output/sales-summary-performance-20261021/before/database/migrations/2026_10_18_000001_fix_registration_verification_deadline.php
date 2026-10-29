<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('registration_email_verifications')) {
            return;
        }
        // Older MariaDB configurations implicitly add ON UPDATE to the first TIMESTAMP.
        // Keep the stored local date/time values, but let only application logic set expiry.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE registration_email_verifications MODIFY expires_at DATETIME NOT NULL');

            return;
        }
        Schema::table('registration_email_verifications', function (Blueprint $table) {
            $table->dateTime('expires_at')->change();
        });
    }

    public function down(): void
    {
        // Do not restore implicit automatic expiry updates on rollback.
    }
};
