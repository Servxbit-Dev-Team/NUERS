<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operations_observations', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->json('payload');
            $t->dateTime('observed_at');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Retain operational evidence; use a reviewed forward migration.');
    }
};
