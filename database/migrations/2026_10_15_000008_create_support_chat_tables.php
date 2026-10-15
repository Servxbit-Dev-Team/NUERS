<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('support_conversations',function(Blueprint $t){$t->uuid('id')->primary();$t->char('visitor_token_hash',64);$t->timestamp('token_expires_at');$t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();$t->string('visitor_name',120);$t->string('visitor_email')->nullable();$t->string('subject',180);$t->string('status',20)->default('open')->index();$t->string('priority',20)->default('normal')->index();$t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();$t->unsignedBigInteger('admin_read_id')->default(0);$t->unsignedBigInteger('visitor_read_id')->default(0);$t->unsignedInteger('revision')->default(1);$t->timestamp('last_message_at')->index();$t->timestamps();});
  Schema::create('support_messages',function(Blueprint $t){$t->id();$t->foreignUuid('conversation_id')->constrained('support_conversations')->cascadeOnDelete();$t->uuid('request_id');$t->string('sender_type',20);$t->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();$t->text('body');$t->timestamp('created_at');$t->unique(['conversation_id','request_id']);$t->index(['conversation_id','id']);});
  Schema::create('support_events',function(Blueprint $t){$t->id();$t->foreignUuid('conversation_id')->constrained('support_conversations')->cascadeOnDelete();$t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();$t->string('action',60);$t->json('details')->nullable();$t->timestamp('created_at');});
 }
 public function down(): void {Schema::dropIfExists('support_events');Schema::dropIfExists('support_messages');Schema::dropIfExists('support_conversations');}
};
