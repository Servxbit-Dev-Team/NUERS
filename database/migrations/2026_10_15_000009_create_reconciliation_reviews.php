<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('reconciliation_reviews', function (Blueprint $t) {
            $t->id();
            $t->string('transaction_id')->unique();
            $t->string('status', 30)->default('open');
            $t->text('reason')->nullable();
            $t->string('reviewed_by')->nullable();
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_reviews');
    }
};
