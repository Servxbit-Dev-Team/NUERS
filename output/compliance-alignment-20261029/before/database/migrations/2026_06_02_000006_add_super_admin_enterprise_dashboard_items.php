<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $listKeys = [
            'ecosystem_accounts',
            'live_transactions',
            'security_operations',
            'api_operations',
            'subscription_governance',
            'settlement_reconciliation',
            'audit_operations',
            'backup_operations',
            'notification_center',
            'enterprise_modules',
        ];

        DB::table('dashboard_list_items')
            ->where('portal', 'super-admin')
            ->whereIn('list_key', $listKeys)
            ->delete();

        $rows = [
            ['ecosystem_accounts', 'Super Admin Seats', 'RBAC operators with break-glass coverage', 'Operational', 'UserCheck', 42, null, ['display' => '42', 'trend' => '+4 provisioned', 'owner' => 'Platform IAM'], 1],
            ['ecosystem_accounts', 'BIR Regulator Accounts', 'Regional revenue and audit access', 'Active', 'Landmark', 318, null, ['display' => '318', 'trend' => '21 pending MFA', 'owner' => 'Regulator Admin'], 2],
            ['ecosystem_accounts', 'Merchant Tenants', 'Businesses connected to NUERS', 'Monitoring', 'Building2', 128420, null, ['display' => '128,420', 'trend' => '2,814 onboarding', 'owner' => 'Merchant Success'], 3],
            ['ecosystem_accounts', 'Client Accounts', 'Citizens, students, patients, and taxpayers', 'Active', 'Users', 8700000, null, ['display' => '8.7M', 'trend' => '+12.5% adoption', 'owner' => 'Client Identity'], 4],

            ['live_transactions', 'EOR issuance stream', 'POS, API, kiosk, and cashier receipt creation', 'Good', 'Receipt', 921843, null, ['display' => '921.8K today', 'sla' => 'P95 180ms', 'owner' => 'Receipt Ops'], 1],
            ['live_transactions', 'Payment authorization rail', 'Cash, QRPH, cards, online banking, and wallets', 'Good', 'CreditCard', 284000, null, ['display' => '284K today', 'sla' => 'P95 240ms', 'owner' => 'Gateway Ops'], 2],
            ['live_transactions', 'Verification portal hits', 'QR, receipt number, and signature authentication', 'Monitoring', 'Globe', 62400, null, ['display' => '62.4K scans', 'sla' => 'P95 120ms', 'owner' => 'Trust Services'], 3],
            ['live_transactions', 'Exception reconciliation', 'Failed settlement, voids, refunds, and suspense ledger', 'Warning', 'AlertTriangle', 842, null, ['display' => '842 cases', 'sla' => '12 due today', 'owner' => 'Revenue Assurance'], 4],
            ['live_transactions', 'Fraud model queue', 'AI anomaly score above investigation threshold', 'High', 'Brain', 37, null, ['display' => '37 high risk', 'sla' => '4 critical', 'owner' => 'AI Audit'], 5],

            ['security_operations', 'MFA Enforcement', 'Strong authentication coverage across privileged roles', 'Good', 'Fingerprint', 94.2, null, [], 1],
            ['security_operations', 'RBAC Policy Sync', 'Role permissions synchronized with portal access', 'Good', 'Lock', 97.8, null, [], 2],
            ['security_operations', 'IP Restriction Coverage', 'Administrative access scoped to trusted networks', 'Monitoring', 'ShieldCheck', 88.5, null, [], 3],
            ['security_operations', 'Device Trust Score', 'Known device and session trust posture', 'Good', 'Cpu', 91.6, null, [], 4],
            ['security_operations', 'Session Risk Guard', 'High-risk login and token behavior blocked', 'Good', 'ShieldAlert', 99.1, null, [], 5],
            ['security_operations', 'Encryption Key Rotation', 'Receipt signing, API, and storage keys rotated', 'Ready', 'Key', 100, null, [], 6],

            ['api_operations', 'OAuth2 / JWT Authority', 'Token issuance, refresh, and revocation control plane', 'Online', 'Key', 99.97, null, [], 1],
            ['api_operations', 'API Gateway Uptime', 'Merchant, agency, and verification API edge health', 'Online', 'Network', 99.98, null, [], 2],
            ['api_operations', 'Webhook Delivery', 'Partner event delivery and retry queue performance', 'Good', 'Zap', 98.7, null, [], 3],
            ['api_operations', 'Rate Limit Guard', 'Abuse prevention and tenant throttling signals', 'Monitoring', 'Activity', 73, null, [], 4],
            ['api_operations', 'POS Integration Sync', 'Branch, terminal, and POS heartbeat coverage', 'Good', 'Cpu', 96.3, null, [], 5],
            ['api_operations', 'Public Verification API', 'Citizen receipt verification service availability', 'Online', 'Globe', 99.99, null, [], 6],

            ['subscription_governance', 'Enterprise Government Plan', 'NGA, GOCC, SUC, hospital, and LGU deployments', 'Active', 'Landmark', 1482, null, ['display' => '1,482 tenants', 'trend' => '+38 this quarter'], 1],
            ['subscription_governance', 'Merchant SaaS Subscriptions', 'Registered businesses under paid NUERS plans', 'Active', 'Building2', 126830, null, ['display' => '126,830', 'trend' => '+2.1% month over month'], 2],
            ['subscription_governance', 'Billing Exceptions', 'Invoices, fees, and plan changes requiring review', 'Review', 'AlertTriangle', 214, null, ['display' => '214 reviews', 'trend' => '32 high priority'], 3],
            ['subscription_governance', 'Revenue Share Settlements', 'Platform fees, gateway fees, and agency settlement value', 'Operational', 'Wallet', 139400000000, null, ['display' => 'PHP 139.4B', 'trend' => 'Current month'], 4],

            ['settlement_reconciliation', 'Daily settlement close', 'Bank, wallet, and agency clearing matched', 'Good', 'Wallet', 99.2, null, ['display' => '99.2% matched'], 1],
            ['settlement_reconciliation', 'Suspense ledger', 'Unmatched payments awaiting revenue assurance', 'Warning', 'FileText', 176, null, ['display' => '176 items'], 2],
            ['settlement_reconciliation', 'Treasury remittance', 'Collections prepared for fund transfer and reporting', 'Operational', 'Landmark', 98.6, null, ['display' => '98.6% ready'], 3],
            ['settlement_reconciliation', 'Refund and void controls', 'Cancellation workflow and approval queue', 'Monitoring', 'ShieldCheck', 64, null, ['display' => '64 pending'], 4],

            ['audit_operations', 'Audit Trail Coverage', 'Login, transaction, receipt, report, and config events', 'Good', 'FileText', 99.9, null, [], 1],
            ['audit_operations', 'Open Investigation Queue', 'Transaction investigation and exception review cases', 'Review', 'ShieldAlert', 312, null, ['display' => '312 cases'], 2],
            ['audit_operations', 'Config Change Review', 'System configuration changes pending approval', 'Monitoring', 'Clock', 18, null, ['display' => '18 changes'], 3],
            ['audit_operations', 'Fraud Escalations', 'Cases escalated from AI risk signals', 'High', 'Brain', 9, null, ['display' => '9 urgent'], 4],

            ['backup_operations', 'Database Backup Success', 'Automated backup jobs completed successfully', 'Healthy', 'HardDrive', 99.9, null, [], 1],
            ['backup_operations', 'RPO / RTO Readiness', 'Disaster recovery objectives within operating threshold', 'Operational', 'Server', 98, null, [], 2],
            ['backup_operations', 'Disaster Recovery Drill', 'Latest continuity exercise and restore validation', 'Ready', 'Database', 96, null, [], 3],

            ['notification_center', 'Critical Broadcasts', 'System-wide notices for agencies and merchants', 'Active', 'Bell', 24, null, ['display' => '24 active'], 1],
            ['notification_center', 'Compliance Alerts Sent', 'Automated reminders and non-compliance notices', 'Active', 'AlertTriangle', 18340, null, ['display' => '18,340 sent'], 2],
            ['notification_center', 'User Inbox Delivery', 'Email, SMS, push, and portal notification delivery rate', 'Good', 'Bell', 99.1, null, [], 3],

            ['enterprise_modules', 'Merchant Management', 'Registry, onboarding, branch, POS, and status controls', 'Active', 'Building2', null, null, [], 1],
            ['enterprise_modules', 'BIR Account Management', 'Regulator accounts, regional access, and audit privileges', 'Active', 'Landmark', null, null, [], 2],
            ['enterprise_modules', 'Client Account Management', 'Citizen, taxpayer, student, and patient account governance', 'Active', 'Users', null, null, [], 3],
            ['enterprise_modules', 'User and RBAC Administration', 'Roles, permissions, MFA, and policy assignments', 'Active', 'UserCheck', null, null, [], 4],
            ['enterprise_modules', 'System Configuration', 'Receipt, payment, security, and tenant-wide settings', 'Ready', 'Cpu', null, null, [], 5],
            ['enterprise_modules', 'Audit Trail Management', 'Traceability for every sensitive platform action', 'Ready', 'FileText', null, null, [], 6],
            ['enterprise_modules', 'Fraud Detection Monitoring', 'AI anomaly detection and smart audit recommendations', 'Active', 'Brain', null, null, [], 7],
            ['enterprise_modules', 'Revenue Analytics', 'Forecasting, heatmaps, source mix, and national trends', 'Active', 'BarChart3', null, null, [], 8],
            ['enterprise_modules', 'Subscription Management', 'Plans, billing exceptions, and platform fee controls', 'Active', 'CreditCard', null, null, [], 9],
            ['enterprise_modules', 'Payment Gateway Management', 'QRPH, GCash, Maya, cards, banks, and reconciliation', 'Active', 'Wallet', null, null, [], 10],
            ['enterprise_modules', 'System Health Monitoring', 'Runtime, database, API, webhook, and BCDR telemetry', 'Healthy', 'Server', null, null, [], 11],
            ['enterprise_modules', 'Notification Center', 'Email, SMS, push, and portal notification operations', 'Active', 'Bell', null, null, [], 12],
        ];

        foreach ($rows as [$listKey, $title, $subtitle, $status, $badge, $primary, $secondary, $metadata, $sort]) {
            DB::table('dashboard_list_items')->insert([
                'portal' => 'super-admin',
                'list_key' => $listKey,
                'title' => $title,
                'subtitle' => $subtitle,
                'status' => $status,
                'badge' => $badge,
                'primary_value' => $primary,
                'secondary_value' => $secondary,
                'metadata' => json_encode($metadata),
                'sort_order' => $sort,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('dashboard_list_items')
            ->where('portal', 'super-admin')
            ->whereIn('list_key', [
                'ecosystem_accounts',
                'live_transactions',
                'security_operations',
                'api_operations',
                'subscription_governance',
                'settlement_reconciliation',
                'audit_operations',
                'backup_operations',
                'notification_center',
                'enterprise_modules',
            ])
            ->delete();
    }
};
