<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rdo_offices', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('rdo_code', 20)->unique();
            $table->string('rdo_name');
            $table->string('region')->index();
            $table->string('city')->nullable()->index();
            $table->text('office_address')->nullable();
            $table->string('head_name')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('phone')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('active')->index();
            $table->json('coverage_area')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rdo_offices');
    }
};
