<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('registration_rdo_mappings', function (Blueprint $t) {
            $t->string('scope', 40)->primary();
            $t->char('city_code', 10)->index();
            $t->char('barangay_code', 10)->nullable()->index();
            $t->string('rdo_code', 10);
            $t->text('source_reference');
            $t->boolean('active')->default(true);
            $t->unsignedInteger('revision')->default(1);
            $t->string('updated_by');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_rdo_mappings');
    }
};
