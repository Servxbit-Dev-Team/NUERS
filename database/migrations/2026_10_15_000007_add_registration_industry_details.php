<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {Schema::table('business_registrations',fn(Blueprint $t)=>$t->json('industry_details')->nullable());}
 public function down(): void {Schema::table('business_registrations',fn(Blueprint $t)=>$t->dropColumn('industry_details'));}
};
