<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_intelligence_kpis', function (Blueprint $table) {
            $table->id();
            $table->string('scope')->index();
            $table->string('widget_key')->index();
            $table->string('label');
            $table->string('value');
            $table->string('subtext')->nullable();
            $table->string('icon')->nullable();
            $table->string('accent')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->unique(['scope', 'widget_key']);
        });

        Schema::create('tax_intelligence_series_points', function (Blueprint $table) {
            $table->id();
            $table->string('scope')->index();
            $table->string('series_key')->index();
            $table->string('label');
            $table->json('values');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['scope', 'series_key', 'sort_order']);
        });

        Schema::create('tax_intelligence_records', function (Blueprint $table) {
            $table->id();
            $table->string('scope')->index();
            $table->string('record_type')->index();
            $table->string('reference')->index();
            $table->string('party_name');
            $table->string('counterparty_name')->nullable();
            $table->string('tin')->nullable();
            $table->decimal('amount', 18, 2)->default(0);
            $table->decimal('vat_amount', 18, 2)->default(0);
            $table->decimal('withholding_amount', 18, 2)->default(0);
            $table->string('status')->index();
            $table->string('risk_level')->nullable()->index();
            $table->unsignedSmallInteger('score')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['scope', 'record_type', 'reference']);
        });

        Schema::create('tax_graph_edges', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 64)->index();
            $table->string('source', 191);
            $table->string('target', 191);
            $table->string('relationship', 191);
            $table->string('risk_level', 50)->nullable()->index();
            $table->decimal('volume', 18, 2)->default(0);
            $table->json('metadata')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['scope', 'source', 'target', 'relationship']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_graph_edges');
        Schema::dropIfExists('tax_intelligence_records');
        Schema::dropIfExists('tax_intelligence_series_points');
        Schema::dropIfExists('tax_intelligence_kpis');
    }
};
