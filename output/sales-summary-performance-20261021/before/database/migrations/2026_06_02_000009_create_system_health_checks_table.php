<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_health_checks', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('service_key')->unique();
            $table->string('category')->index();
            $table->string('service_name');
            $table->string('status')->default('online')->index();
            $table->decimal('uptime_percentage', 5, 2)->default(0);
            $table->unsignedInteger('p95_latency_ms')->nullable();
            $table->unsignedInteger('error_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamp('checked_at')->nullable()->index();
            $table->timestamps();
        });

        $now = now();
        $rows = [
            ['receipt_engine', 'runtime', 'Receipt Engine', 'online', 99.96, 180, 0, ['icon' => 'Receipt']],
            ['payment_gateway', 'runtime', 'Payment Gateway Rail', 'online', 99.94, 240, 1, ['icon' => 'CreditCard']],
            ['public_verification_api', 'api', 'Public Verification API', 'online', 99.99, 120, 0, ['icon' => 'Globe']],

            ['ip_restriction_guard', 'security', 'IP Restriction Coverage', 'monitoring', 88.50, null, 0, ['icon' => 'ShieldCheck']],
            ['device_trust', 'security', 'Device Trust Score', 'online', 91.60, null, 0, ['icon' => 'Cpu']],
            ['session_risk_guard', 'security', 'Session Risk Guard', 'online', 99.10, null, 0, ['icon' => 'ShieldAlert']],
            ['key_rotation', 'security', 'Encryption Key Rotation', 'ready', 100.00, null, 0, ['icon' => 'Key']],

            ['oauth_authority', 'api', 'OAuth2 / JWT Authority', 'online', 99.97, 95, 0, ['icon' => 'Key']],
            ['api_gateway', 'api', 'API Gateway Uptime', 'online', 99.98, 140, 0, ['icon' => 'Network']],
            ['webhook_delivery', 'api', 'Webhook Delivery', 'good', 98.70, 220, 2, ['icon' => 'Zap']],
            ['rate_limit_guard', 'api', 'Rate Limit Guard', 'monitoring', 73.00, 45, 4, ['icon' => 'Activity']],
            ['pos_integration_sync', 'api', 'POS Integration Sync', 'good', 96.30, 160, 1, ['icon' => 'Cpu']],

            ['database_backup', 'backup', 'Database Backup Success', 'healthy', 99.90, null, 0, ['icon' => 'HardDrive']],
            ['rpo_rto_readiness', 'backup', 'RPO / RTO Readiness', 'operational', 98.00, null, 0, ['icon' => 'Server']],
            ['disaster_recovery_drill', 'backup', 'Disaster Recovery Drill', 'ready', 96.00, null, 0, ['icon' => 'Database']],

            ['notification_delivery', 'notifications', 'User Inbox Delivery', 'good', 99.10, 210, 1, ['icon' => 'Bell']],
        ];

        foreach ($rows as [$serviceKey, $category, $serviceName, $status, $uptime, $latency, $errors, $metadata]) {
            DB::table('system_health_checks')->insert([
                'id' => (string) Str::uuid(),
                'service_key' => $serviceKey,
                'category' => $category,
                'service_name' => $serviceName,
                'status' => $status,
                'uptime_percentage' => $uptime,
                'p95_latency_ms' => $latency,
                'error_count' => $errors,
                'metadata' => json_encode($metadata),
                'checked_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('system_health_checks');
    }
};
