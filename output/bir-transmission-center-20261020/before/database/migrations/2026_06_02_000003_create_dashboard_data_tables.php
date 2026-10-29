<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_kpis', function (Blueprint $table) {
            $table->id();
            $table->string('portal')->index();
            $table->string('widget_key')->index();
            $table->string('label');
            $table->string('value');
            $table->string('subtext')->nullable();
            $table->string('icon')->nullable();
            $table->string('accent')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->unique(['portal', 'widget_key']);
        });

        Schema::create('dashboard_series_points', function (Blueprint $table) {
            $table->id();
            $table->string('portal')->index();
            $table->string('series_key')->index();
            $table->string('label');
            $table->json('values');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['portal', 'series_key', 'sort_order']);
        });

        Schema::create('dashboard_list_items', function (Blueprint $table) {
            $table->id();
            $table->string('portal')->index();
            $table->string('list_key')->index();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('status')->nullable();
            $table->string('badge')->nullable();
            $table->decimal('primary_value', 18, 2)->nullable();
            $table->decimal('secondary_value', 18, 2)->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['portal', 'list_key', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_list_items');
        Schema::dropIfExists('dashboard_series_points');
        Schema::dropIfExists('dashboard_kpis');
    }
};
