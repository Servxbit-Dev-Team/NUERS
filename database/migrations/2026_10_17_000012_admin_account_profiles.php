<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('admin_account_profiles', function (Blueprint $t) {
            $t->unsignedBigInteger('user_id')->primary();
            $t->text('encrypted_details');
            $t->string('avatar_path', 150)->nullable();
            $t->unsignedInteger('revision')->default(1);
            $t->dateTime('created_at');
            $t->dateTime('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_account_profiles');
    }
};
