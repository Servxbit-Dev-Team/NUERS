<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $now = Carbon::now();

        DB::table('users')->updateOrInsert(
            ['email' => 'm.sioson@servxbit.com'],
            [
                'name' => 'Servxbit Business Account',
                'email_verified_at' => $now,
                'password' => Hash::make('123456'),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        DB::table('users')->updateOrInsert(
            ['email' => 'admin@nuers.com'],
            [
                'name' => 'NUERS Administrator',
                'email_verified_at' => $now,
                'password' => Hash::make('123456'),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        DB::table('users')->updateOrInsert(
            ['email' => 'customer@nuers.com'],
            [
                'name' => 'ABC Trading Co.',
                'email_verified_at' => $now,
                'password' => Hash::make('123456'),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        DB::table('users')->updateOrInsert(
            ['email' => 'bir@nuers.com'],
            [
                'name' => 'BIR Revenue Officer',
                'email_verified_at' => $now,
                'password' => Hash::make('123456'),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        DB::table('users')->updateOrInsert(
            ['email' => 'rdo047@nuers.com'],
            [
                'name' => 'RDO 047 Revenue Officer',
                'email_verified_at' => $now,
                'password' => Hash::make('123456'),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        DB::table('users')->updateOrInsert(
            ['email' => 'client@nuers.com'],
            [
                'name' => 'Juan Dela Cruz',
                'email_verified_at' => $now,
                'password' => Hash::make('123456'),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $servxbitMerchantId = DB::table('users')->where('email', 'm.sioson@servxbit.com')->value('id');
        $adminId = DB::table('users')->where('email', 'admin@nuers.com')->value('id');
        $merchantUserId = DB::table('users')->where('email', 'customer@nuers.com')->value('id');
        $birUserId = DB::table('users')->where('email', 'bir@nuers.com')->value('id');
        $rdoUserId = DB::table('users')->where('email', 'rdo047@nuers.com')->value('id');
        $clientUserId = DB::table('users')->where('email', 'client@nuers.com')->value('id');

        $profiles = [
            [
                'id' => $servxbitMerchantId,
                'email' => 'm.sioson@servxbit.com',
                'role' => 'merchant',
                'full_name' => 'Servxbit Business Account',
                'organization' => 'Servxbit Business Account',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => $adminId,
                'email' => 'admin@nuers.com',
                'role' => 'admin',
                'full_name' => 'NUERS Administrator',
                'organization' => 'Bureau of Internal Revenue',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => $merchantUserId,
                'email' => 'customer@nuers.com',
                'role' => 'merchant',
                'full_name' => 'ABC Trading Co.',
                'organization' => 'ABC Trading Co.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => $birUserId,
                'email' => 'bir@nuers.com',
                'role' => 'bir',
                'full_name' => 'BIR Revenue Officer',
                'organization' => 'Bureau of Internal Revenue',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => $rdoUserId,
                'email' => 'rdo047@nuers.com',
                'role' => 'rdo',
                'full_name' => 'RDO 047 Revenue Officer',
                'organization' => '047 - RDO 047 - East Makati',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => $clientUserId,
                'email' => 'client@nuers.com',
                'role' => 'client',
                'full_name' => 'Juan Dela Cruz',
                'organization' => 'Citizen Account',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        foreach ($profiles as $profile) {
            DB::table('profiles')->updateOrInsert(['id' => $profile['id']], $profile);
        }

        $merchants = [
            [
                'id' => '10000000-0000-4000-8000-000000000001',
                'merchant_account_id' => $merchantUserId,
                'tin' => '123-456-789-000',
                'business_name' => 'ABC Trading Co.',
                'owner_name' => 'Maria Santos',
                'email' => 'customer@nuers.com',
                'phone' => '+63 917 555 0184',
                'address' => 'Ayala Avenue, Makati City',
                'city' => 'Makati',
                'zip_code' => '1226',
                'rdo_code' => '047',
                'rdo_name' => 'RDO 047 - East Makati',
                'sector' => 'retail',
                'region' => 'NCR',
                'business_type' => 'corporation',
                'bir_registration_date' => '2022-05-17',
                'vat_registered' => true,
                'status' => 'active',
                'compliance_score' => 94,
                'monthly_revenue' => 16200000,
                'annual_revenue' => 194400000,
                'employee_count' => 86,
                'pos_system' => 'Oracle MICROS',
                'merchant_account_email' => 'customer@nuers.com',
                'notes' => 'Pilot merchant for NUERS rollout.',
                'last_audit_date' => '2026-04-15',
                'next_audit_date' => '2026-07-15',
                'registration_date' => '2026-01-10',
                'website' => 'https://abc-trading.example',
                'branch_count' => 12,
                'created_at' => $now->copy()->subDays(25),
                'updated_at' => $now,
            ],
            [
                'id' => '10000000-0000-4000-8000-000000000002',
                'merchant_account_id' => null,
                'tin' => '987-654-321-000',
                'business_name' => 'XYZ Enterprises',
                'owner_name' => 'Jose Reyes',
                'email' => 'ops@xyz-enterprises.example',
                'phone' => '+63 918 555 0141',
                'address' => 'IT Park, Cebu City',
                'city' => 'Cebu City',
                'zip_code' => '6000',
                'rdo_code' => '081',
                'rdo_name' => 'RDO 081 - Cebu City North',
                'sector' => 'services',
                'region' => 'Region VII',
                'business_type' => 'corporation',
                'bir_registration_date' => '2021-08-02',
                'vat_registered' => true,
                'status' => 'under_review',
                'compliance_score' => 67,
                'monthly_revenue' => 7800000,
                'annual_revenue' => 93600000,
                'employee_count' => 42,
                'pos_system' => 'Custom/Proprietary',
                'merchant_account_email' => 'ops@xyz-enterprises.example',
                'notes' => 'Reviewing receipt transmission variance.',
                'last_audit_date' => '2026-03-05',
                'next_audit_date' => '2026-06-25',
                'registration_date' => '2026-02-03',
                'website' => 'https://xyz-enterprises.example',
                'branch_count' => 5,
                'created_at' => $now->copy()->subDays(18),
                'updated_at' => $now,
            ],
            [
                'id' => '10000000-0000-4000-8000-000000000003',
                'merchant_account_id' => null,
                'tin' => '456-111-222-000',
                'business_name' => 'Quick Mart Chain',
                'owner_name' => 'Liza Cruz',
                'email' => 'finance@quickmart.example',
                'phone' => '+63 919 555 0162',
                'address' => 'San Fernando, Pampanga',
                'city' => 'San Fernando',
                'zip_code' => '2000',
                'rdo_code' => '021',
                'rdo_name' => 'RDO 021 - San Fernando, Pampanga',
                'sector' => 'food_beverage',
                'region' => 'Region III',
                'business_type' => 'corporation',
                'bir_registration_date' => '2020-11-20',
                'vat_registered' => true,
                'status' => 'active',
                'compliance_score' => 88,
                'monthly_revenue' => 12100000,
                'annual_revenue' => 145200000,
                'employee_count' => 117,
                'pos_system' => 'Square POS',
                'merchant_account_email' => 'finance@quickmart.example',
                'notes' => 'High-volume retail chain.',
                'last_audit_date' => '2026-02-20',
                'next_audit_date' => '2026-08-20',
                'registration_date' => '2026-02-18',
                'website' => 'https://quickmart.example',
                'branch_count' => 26,
                'created_at' => $now->copy()->subDays(12),
                'updated_at' => $now,
            ],
        ];

        foreach ($merchants as $merchant) {
            DB::table('merchants')->updateOrInsert(['tin' => $merchant['tin']], $merchant);
        }

        $rdoOffices = [
            [
                'id' => '98000000-0000-4000-8000-000000000047',
                'rdo_code' => '047',
                'rdo_name' => 'RDO 047 - East Makati',
                'region' => 'NCR',
                'city' => 'Makati City',
                'office_address' => 'BIR Revenue District Office, East Makati, Metro Manila',
                'head_name' => 'RDO Revenue Officer 047',
                'email' => 'rdo047@nuers.com',
                'phone' => '+63 2 8847 0470',
                'user_id' => $rdoUserId,
                'status' => 'active',
                'coverage_area' => json_encode(['Makati City', 'Barangay Bel-Air', 'Ayala Center', 'San Lorenzo']),
                'notes' => 'Pilot RDO portal account for NUERS scoped monitoring.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => '98000000-0000-4000-8000-000000000081',
                'rdo_code' => '081',
                'rdo_name' => 'RDO 081 - Cebu City North',
                'region' => 'Region VII',
                'city' => 'Cebu City',
                'office_address' => 'BIR Revenue District Office, Cebu City North',
                'head_name' => 'RDO Revenue Officer 081',
                'email' => 'rdo081@nuers.com',
                'phone' => '+63 32 881 0810',
                'user_id' => null,
                'status' => 'active',
                'coverage_area' => json_encode(['Cebu City', 'Lahug', 'Mabolo', 'Capitol Site']),
                'notes' => 'Regional RDO monitoring account available for onboarding.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => '98000000-0000-4000-8000-000000000021',
                'rdo_code' => '021',
                'rdo_name' => 'RDO 021 - San Fernando, Pampanga',
                'region' => 'Region III',
                'city' => 'San Fernando',
                'office_address' => 'BIR Revenue District Office, San Fernando, Pampanga',
                'head_name' => 'RDO Revenue Officer 021',
                'email' => 'rdo021@nuers.com',
                'phone' => '+63 45 821 0210',
                'user_id' => null,
                'status' => 'active',
                'coverage_area' => json_encode(['San Fernando', 'Pampanga business district']),
                'notes' => 'Central Luzon RDO office profile.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        foreach ($rdoOffices as $office) {
            DB::table('rdo_offices')->updateOrInsert(['rdo_code' => $office['rdo_code']], $office);
        }

        $notifications = [
            [
                'id' => '40000000-0000-4000-8000-000000000001',
                'title' => 'High-risk transaction pattern detected',
                'message' => 'ABC Trading Co. exceeded the configured receipt void threshold for the last 24 hours.',
                'type' => 'risk',
                'priority' => 'critical',
                'is_read' => false,
                'created_at' => $now->copy()->subMinutes(18),
                'updated_at' => $now->copy()->subMinutes(18),
            ],
            [
                'id' => '40000000-0000-4000-8000-000000000002',
                'title' => 'VAT filing deadline approaching',
                'message' => 'Q2 VAT filing reminders have been queued for registered merchants.',
                'type' => 'deadline',
                'priority' => 'high',
                'is_read' => false,
                'created_at' => $now->copy()->subHours(2),
                'updated_at' => $now->copy()->subHours(2),
            ],
            [
                'id' => '40000000-0000-4000-8000-000000000003',
                'title' => 'Regional compliance milestone reached',
                'message' => 'NCR merchants reached 98% on-time receipt transmission compliance.',
                'type' => 'achievement',
                'priority' => 'medium',
                'is_read' => true,
                'created_at' => $now->copy()->subDay(),
                'updated_at' => $now->copy()->subDay(),
            ],
        ];

        foreach ($notifications as $notification) {
            DB::table('notifications')->updateOrInsert(['title' => $notification['title']], $notification);
        }

        foreach (range(1, 24) as $i) {
            $merchant = $merchants[array_rand($merchants)];
            $gross = random_int(850, 56000);
            $vatable = round($gross / 1.12, 2);
            $vat = round($gross - $vatable, 2);
            $transactionId = '20000000-0000-4000-8000-'.str_pad((string) $i, 12, '0', STR_PAD_LEFT);
            $receiptId = '30000000-0000-4000-8000-'.str_pad((string) $i, 12, '0', STR_PAD_LEFT);
            $issuedAt = $now->copy()->subMinutes($i * 17);

            DB::table('merchant_transactions')->updateOrInsert(['transaction_ref' => 'TXN-2026-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT)], [
                'id' => $transactionId,
                'merchant_id' => $merchant['id'],
                'merchant_ref_id' => 'MREF-'.$i,
                'transaction_ref' => 'TXN-2026-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'amount' => $gross,
                'vat_amount' => $vat,
                'vatable_sales' => $vatable,
                'net_amount' => $vatable,
                'payment_method' => ['cash', 'card', 'gcash', 'maya', 'bank_transfer'][array_rand(['cash', 'card', 'gcash', 'maya', 'bank_transfer'])],
                'region' => $merchant['region'],
                'branch' => $merchant['city'].' Main',
                'rdo_code' => $merchant['rdo_code'] ?? null,
                'rdo_name' => $merchant['rdo_name'] ?? null,
                'channel' => ['pos', 'api', 'ecommerce'][array_rand(['pos', 'api', 'ecommerce'])],
                'transaction_type' => 'sale',
                'customer_name' => ['Walk-in Customer', 'Juan Dela Cruz', 'Ana Lim'][array_rand(['Walk-in Customer', 'Juan Dela Cruz', 'Ana Lim'])],
                'customer_tin' => null,
                'status' => ['completed', 'completed', 'completed', 'pending'][array_rand(['completed', 'completed', 'completed', 'pending'])],
                'receipt_id' => $receiptId,
                'created_at' => $issuedAt,
                'updated_at' => $issuedAt,
            ]);

            DB::table('transaction_receipts')->updateOrInsert(['receipt_number' => 'OR-2026-'.str_pad((string) $i, 7, '0', STR_PAD_LEFT)], [
                'id' => $receiptId,
                'transaction_id' => $transactionId,
                'merchant_id' => $merchant['id'],
                'receipt_number' => 'OR-2026-'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                'series_number' => 'NUERS-'.$i,
                'bir_accreditation' => 'BIR-NUERS-2026-001',
                'receipt_type' => 'official_receipt',
                'merchant_name' => $merchant['business_name'],
                'merchant_tin' => $merchant['tin'],
                'rdo_code' => $merchant['rdo_code'] ?? null,
                'rdo_name' => $merchant['rdo_name'] ?? null,
                'merchant_address' => $merchant['address'],
                'merchant_vat_reg' => $merchant['vat_registered'] ? 'VAT Registered' : 'Non-VAT',
                'buyer_name' => null,
                'buyer_tin' => null,
                'gross_amount' => $gross,
                'discount_amount' => 0,
                'vatable_sales' => $vatable,
                'vat_exempt_sales' => 0,
                'zero_rated_sales' => 0,
                'vat_amount' => $vat,
                'total_due' => $gross,
                'items' => json_encode([
                    [
                        'description' => 'Retail sale',
                        'qty' => 1,
                        'unit_price' => $gross,
                        'vat' => $vat,
                        'amount' => $gross,
                    ],
                ]),
                'status' => 'issued',
                'issued_at' => $issuedAt,
                'created_at' => $issuedAt,
                'updated_at' => $issuedAt,
            ]);
        }

        $roles = [
            ['slug' => 'super_admin', 'name' => 'Super Admin', 'portal' => 'super-admin', 'description' => 'Manages the full NUERS ecosystem.'],
            ['slug' => 'bir_regulator', 'name' => 'BIR Regulator', 'portal' => 'bir', 'description' => 'Monitors revenue, compliance, audit, and reporting.'],
            ['slug' => 'rdo_officer', 'name' => 'RDO Officer', 'portal' => 'rdo', 'description' => 'Manages businesses, transactions, receipts, and compliance under one Revenue District Office.'],
            ['slug' => 'merchant_admin', 'name' => 'Merchant Admin', 'portal' => 'merchant', 'description' => 'Issues receipts and manages merchant operations.'],
            ['slug' => 'client_user', 'name' => 'Client User', 'portal' => 'client', 'description' => 'Pays fees, manages wallet, and verifies receipts.'],
        ];

        foreach ($roles as $role) {
            DB::table('roles')->updateOrInsert(['slug' => $role['slug']], [
                ...$role,
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $permissions = [
            ['slug' => 'platform.manage', 'module' => 'platform', 'action' => 'manage', 'description' => 'Manage platform configuration and system controls.'],
            ['slug' => 'users.manage', 'module' => 'identity', 'action' => 'manage', 'description' => 'Manage users, roles, permissions, and MFA.'],
            ['slug' => 'revenue.monitor', 'module' => 'revenue', 'action' => 'monitor', 'description' => 'Monitor national and regional revenue.'],
            ['slug' => 'audit.review', 'module' => 'audit', 'action' => 'review', 'description' => 'Review audit events and investigations.'],
            ['slug' => 'receipts.issue', 'module' => 'receipts', 'action' => 'issue', 'description' => 'Issue electronic official receipts.'],
            ['slug' => 'receipts.verify', 'module' => 'receipts', 'action' => 'verify', 'description' => 'Verify receipt number, QR, and signature.'],
            ['slug' => 'payments.process', 'module' => 'payments', 'action' => 'process', 'description' => 'Process payments and settlements.'],
            ['slug' => 'wallet.manage', 'module' => 'wallet', 'action' => 'manage', 'description' => 'Manage wallet top-ups, transfers, and payments.'],
            ['slug' => 'ai.review', 'module' => 'ai', 'action' => 'review', 'description' => 'Review AI risk and forecasting insights.'],
        ];

        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(['slug' => $permission['slug']], [
                ...$permission,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $rolePermissionMap = [
            'super_admin' => ['platform.manage', 'users.manage', 'revenue.monitor', 'audit.review', 'receipts.verify', 'payments.process', 'ai.review'],
            'bir_regulator' => ['revenue.monitor', 'audit.review', 'receipts.verify', 'ai.review'],
            'rdo_officer' => ['revenue.monitor', 'audit.review', 'receipts.verify', 'ai.review'],
            'merchant_admin' => ['receipts.issue', 'receipts.verify', 'payments.process'],
            'client_user' => ['receipts.verify', 'wallet.manage'],
        ];

        foreach ($rolePermissionMap as $roleSlug => $permissionSlugs) {
            $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');
            foreach ($permissionSlugs as $permissionSlug) {
                $permissionId = DB::table('permissions')->where('slug', $permissionSlug)->value('id');
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permissionId],
                    ['created_at' => $now, 'updated_at' => $now],
                );
            }
        }

        DB::table('bir_accounts')->updateOrInsert(['employee_id' => 'BIR-NUERS-001'], [
            'id' => '50000000-0000-4000-8000-000000000001',
            'user_id' => $birUserId,
            'employee_id' => 'BIR-NUERS-001',
            'full_name' => 'BIR Revenue Officer',
            'email' => 'bir@nuers.com',
            'region' => 'National Office',
            'office' => 'Revenue Monitoring and Compliance',
            'role_title' => 'Revenue Officer IV',
            'clearance_level' => 'regulator',
            'mfa_enabled' => true,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('client_accounts')->updateOrInsert(['account_number' => 'CL-2026-000001'], [
            'id' => '60000000-0000-4000-8000-000000000001',
            'user_id' => $clientUserId,
            'account_number' => 'CL-2026-000001',
            'full_name' => 'Juan Dela Cruz',
            'account_type' => 'citizen',
            'email' => 'client@nuers.com',
            'mobile' => '+63 917 555 0199',
            'wallet_balance' => 12840,
            'mfa_enabled' => true,
            'notification_preferences' => json_encode(['sms' => true, 'email' => true, 'push' => true]),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $agencies = [
            ['id' => '70000000-0000-4000-8000-000000000001', 'name' => 'Quezon City Treasurer Office', 'agency_type' => 'LGU', 'region' => 'NCR', 'lgu_code' => 'QC', 'monthly_volume' => 680000, 'compliance_score' => 97],
            ['id' => '70000000-0000-4000-8000-000000000002', 'name' => 'State University Cashier', 'agency_type' => 'SUC', 'region' => 'Region III', 'lgu_code' => null, 'monthly_volume' => 180000, 'compliance_score' => 94],
            ['id' => '70000000-0000-4000-8000-000000000003', 'name' => 'Public Hospital Billing Network', 'agency_type' => 'hospital', 'region' => 'NCR', 'lgu_code' => null, 'monthly_volume' => 280000, 'compliance_score' => 91],
        ];

        foreach ($agencies as $agency) {
            DB::table('agency_accounts')->updateOrInsert(['id' => $agency['id']], [
                ...$agency,
                'subscription_plan' => 'Enterprise',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $gateways = [
            ['id' => '80000000-0000-4000-8000-000000000001', 'provider' => 'QRPH', 'channel' => 'qr', 'fee_rate' => 0.2500, 'uptime_percentage' => 99.98],
            ['id' => '80000000-0000-4000-8000-000000000002', 'provider' => 'GCash', 'channel' => 'e_wallet', 'fee_rate' => 0.5000, 'uptime_percentage' => 99.94],
            ['id' => '80000000-0000-4000-8000-000000000003', 'provider' => 'Maya', 'channel' => 'e_wallet', 'fee_rate' => 0.5000, 'uptime_percentage' => 99.91],
            ['id' => '80000000-0000-4000-8000-000000000004', 'provider' => 'BancNet', 'channel' => 'online_banking', 'fee_rate' => 0.3500, 'uptime_percentage' => 99.87],
        ];

        foreach ($gateways as $gateway) {
            DB::table('payment_gateways')->updateOrInsert(['id' => $gateway['id']], [
                ...$gateway,
                'status' => 'online',
                'settlement_window' => 'T+1',
                'last_health_check_at' => $now,
                'configuration' => json_encode(['environment' => 'production-ready', 'reconciliation' => true]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('api_clients')->updateOrInsert(['client_key' => 'nuers-demo-gateway'], [
            'id' => '90000000-0000-4000-8000-000000000001',
            'owner_type' => 'platform',
            'owner_id' => 'NUERS',
            'name' => 'NUERS Enterprise API Gateway',
            'client_key' => 'nuers-demo-gateway',
            'scopes' => json_encode(['receipts.issue', 'receipts.verify', 'payments.process', 'audit.write']),
            'rate_limit_per_minute' => 5000,
            'status' => 'active',
            'last_used_at' => $now->copy()->subMinutes(4),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('subscriptions')->updateOrInsert(['id' => '91000000-0000-4000-8000-000000000001'], [
            'id' => '91000000-0000-4000-8000-000000000001',
            'account_type' => 'agency',
            'account_id' => '70000000-0000-4000-8000-000000000001',
            'plan_name' => 'Enterprise National',
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'amount' => 250000,
            'next_billing_at' => $now->copy()->addMonth(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('settlement_batches')->updateOrInsert(['batch_number' => 'SET-2026-000001'], [
            'id' => '92000000-0000-4000-8000-000000000001',
            'batch_number' => 'SET-2026-000001',
            'gateway_id' => '80000000-0000-4000-8000-000000000001',
            'gross_amount' => 18420000,
            'net_amount' => 18373950,
            'transaction_count' => 48210,
            'status' => 'settled',
            'settled_at' => $now->copy()->subHours(3),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('audit_events')->updateOrInsert(['id' => '93000000-0000-4000-8000-000000000001'], [
            'id' => '93000000-0000-4000-8000-000000000001',
            'actor_id' => $adminId,
            'actor_email' => 'admin@nuers.com',
            'event_type' => 'system.configuration.updated',
            'auditable_type' => 'payment_gateway',
            'auditable_id' => '80000000-0000-4000-8000-000000000001',
            'ip_address' => '203.0.113.10',
            'device_fingerprint' => 'live-demo-device',
            'severity' => 'info',
            'metadata' => json_encode(['field' => 'settlement_window', 'new_value' => 'T+1']),
            'created_at' => $now->copy()->subMinutes(42),
            'updated_at' => $now->copy()->subMinutes(42),
        ]);

        DB::table('fraud_signals')->updateOrInsert(['id' => '94000000-0000-4000-8000-000000000001'], [
            'id' => '94000000-0000-4000-8000-000000000001',
            'merchant_id' => '10000000-0000-4000-8000-000000000002',
            'signal_type' => 'receipt_gap_anomaly',
            'risk_score' => 91,
            'severity' => 'high',
            'amount' => 2400000,
            'status' => 'open',
            'recommendation' => 'Open audit case and compare POS sequence against transmitted EOR series.',
            'detected_at' => $now->copy()->subMinutes(11),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('ai_insights')->updateOrInsert(['id' => '95000000-0000-4000-8000-000000000001'], [
            'id' => '95000000-0000-4000-8000-000000000001',
            'model' => 'nuers-risk-engine-v1',
            'insight_type' => 'revenue_forecast',
            'title' => 'June revenue collection forecast exceeds target',
            'summary' => 'Forecast indicates a 7.8% upside driven by LGU permit renewals and hospital payment digitization.',
            'confidence_score' => 92.50,
            'impact_area' => 'revenue',
            'metadata' => json_encode(['forecast_month' => '2026-06', 'variance' => 7.8]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('receipt_verifications')->updateOrInsert(['id' => '96000000-0000-4000-8000-000000000001'], [
            'id' => '96000000-0000-4000-8000-000000000001',
            'receipt_id' => '30000000-0000-4000-8000-000000000001',
            'receipt_number' => 'OR-2026-0000001',
            'verification_method' => 'receipt_number',
            'verifier_ip' => '203.0.113.11',
            'status' => 'verified',
            'signature_valid' => true,
            'verified_at' => $now->copy()->subMinutes(5),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $dashboardPortals = ['super-admin', 'bir', 'merchant', 'client'];
        DB::table('dashboard_kpis')->whereIn('portal', $dashboardPortals)->delete();
        DB::table('dashboard_series_points')->whereIn('portal', $dashboardPortals)->delete();
        DB::table('dashboard_list_items')->whereIn('portal', $dashboardPortals)->delete();

        $dashboardKpis = [
            ['super-admin', 'total_transactions', 'Total Transactions', '58.4M', '+14.8% month over month', 'Activity', 'border-l-primary', 1],
            ['super-admin', 'eor_issued', 'EOR Issued', '41.7M', 'QR signed and archived', 'Receipt', 'border-l-chart-2', 2],
            ['super-admin', 'revenue_processed', 'Revenue Processed', 'PHP 139.4B', 'Current month', 'Wallet', 'border-l-success', 3],
            ['super-admin', 'active_merchants', 'Active Merchants', '128,420', '2,814 pending onboarding', 'Building2', 'border-l-chart-3', 4],
            ['super-admin', 'active_clients', 'Active Clients', '8.7M', 'Citizens, students, patients', 'Users', 'border-l-chart-4', 5],
            ['bir', 'total_revenue', 'Total Revenue', 'PHP 148.8B', '+8.7% vs target', 'TrendingUp', null, 1],
            ['bir', 'tax_collections', 'Tax Collections', 'PHP 17.86B', 'VAT, income, withholding', 'Landmark', null, 2],
            ['bir', 'active_merchants', 'Active Merchants', '128,420', 'Nationwide registry', 'Building2', null, 3],
            ['bir', 'receipts_issued', 'Receipts Issued', '41.7M', 'Digitally signed', 'Receipt', null, 4],
            ['bir', 'compliance_rate', 'Compliance Rate', '96.4%', 'Current period', 'ShieldCheck', null, 5],
            ['bir', 'non_compliant', 'Non-Compliant', '1,247', 'Needs action', 'AlertTriangle', null, 6],
            ['client', 'wallet_balance', 'Wallet Balance', 'PHP 12,840.00', '+PHP 3,000 top-up this week', 'Wallet', 'border-l-primary', 1],
            ['client', 'recent_transactions', 'Recent Transactions', '24', '4 awaiting receipt archive', 'CreditCard', 'border-l-primary', 2],
            ['client', 'recent_receipts', 'Recent Receipts', '18', 'All verified and signed', 'Receipt', 'border-l-primary', 3],
            ['client', 'upcoming_payments', 'Upcoming Payments', '3', 'Due within 7 days', 'Bell', 'border-l-primary', 4],
            ['merchant', 'todays_revenue', "Today's Revenue", 'PHP 284,500.00', '+12.4% vs yesterday', 'TrendingUp', 'border-l-success', 1],
            ['merchant', 'todays_transactions', "Today's Transactions", '1,247', 'Live counter from transaction stream', 'Activity', 'border-l-primary', 2],
            ['merchant', 'avg_transaction', 'Avg. Transaction', 'PHP 228.12', '-2.1% vs yesterday', 'Receipt', 'border-l-warning', 3],
            ['merchant', 'active_customers', 'Active Customers', '892', '+8 new today', 'Users', 'border-l-chart-3', 4],
            ['merchant', 'monthly_revenue', 'Monthly Revenue', 'PHP 16.20M', '+15.7% vs last month', 'Wallet', 'border-l-chart-1', 5],
            ['merchant', 'tax_summary', 'Tax Summary', 'PHP 34,140.00', '12% VAT collected today', 'ShieldCheck', 'border-l-chart-2', 6],
        ];

        foreach ($dashboardKpis as [$portal, $key, $label, $value, $subtext, $icon, $accent, $sort]) {
            DB::table('dashboard_kpis')->insert([
                'portal' => $portal,
                'widget_key' => $key,
                'label' => $label,
                'value' => $value,
                'subtext' => $subtext,
                'icon' => $icon,
                'accent' => $accent,
                'sort_order' => $sort,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $seriesRows = [
            ['super-admin', 'collection_trend', 'Jan', ['daily' => 4.2, 'monthly' => 91, 'annual' => 620], 1],
            ['super-admin', 'collection_trend', 'Feb', ['daily' => 4.8, 'monthly' => 96, 'annual' => 718], 2],
            ['super-admin', 'collection_trend', 'Mar', ['daily' => 5.1, 'monthly' => 104, 'annual' => 826], 3],
            ['super-admin', 'collection_trend', 'Apr', ['daily' => 5.4, 'monthly' => 111, 'annual' => 934], 4],
            ['super-admin', 'collection_trend', 'May', ['daily' => 6.2, 'monthly' => 127, 'annual' => 1076], 5],
            ['super-admin', 'collection_trend', 'Jun', ['daily' => 6.8, 'monthly' => 139, 'annual' => 1218], 6],
            ['super-admin', 'source_mix', 'Government Fees', ['value' => 32], 1],
            ['super-admin', 'source_mix', 'Merchants', ['value' => 28], 2],
            ['super-admin', 'source_mix', 'LGUs', ['value' => 18], 3],
            ['super-admin', 'source_mix', 'Hospitals', ['value' => 12], 4],
            ['super-admin', 'source_mix', 'Schools', ['value' => 10], 5],
            ['bir', 'collection_trend', 'Mon', ['revenue' => 3.4, 'tax' => 0.41, 'receipts' => 1.2], 1],
            ['bir', 'collection_trend', 'Tue', ['revenue' => 3.8, 'tax' => 0.46, 'receipts' => 1.4], 2],
            ['bir', 'collection_trend', 'Wed', ['revenue' => 4.1, 'tax' => 0.49, 'receipts' => 1.5], 3],
            ['bir', 'collection_trend', 'Thu', ['revenue' => 4.6, 'tax' => 0.55, 'receipts' => 1.8], 4],
            ['bir', 'collection_trend', 'Fri', ['revenue' => 5.2, 'tax' => 0.62, 'receipts' => 2.1], 5],
            ['bir', 'collection_trend', 'Sat', ['revenue' => 3.9, 'tax' => 0.47, 'receipts' => 1.3], 6],
            ['bir', 'collection_trend', 'Sun', ['revenue' => 2.7, 'tax' => 0.32, 'receipts' => 0.9], 7],
            ['bir', 'revenue_by_region', 'NCR', ['revenue' => 58.4, 'tax' => 7.01, 'compliance' => 98.2], 1],
            ['bir', 'revenue_by_region', 'IV-A', ['revenue' => 31.8, 'tax' => 3.82, 'compliance' => 95.6], 2],
            ['bir', 'revenue_by_region', 'III', ['revenue' => 24.7, 'tax' => 2.96, 'compliance' => 94.1], 3],
            ['bir', 'revenue_by_region', 'VII', ['revenue' => 18.6, 'tax' => 2.23, 'compliance' => 91.4], 4],
            ['bir', 'revenue_by_region', 'XI', ['revenue' => 13.9, 'tax' => 1.67, 'compliance' => 90.8], 5],
            ['client', 'wallet_activity', 'Mon', ['payments' => 1200, 'topup' => 2500], 1],
            ['client', 'wallet_activity', 'Tue', ['payments' => 850, 'topup' => 0], 2],
            ['client', 'wallet_activity', 'Wed', ['payments' => 4200, 'topup' => 5000], 3],
            ['client', 'wallet_activity', 'Thu', ['payments' => 630, 'topup' => 0], 4],
            ['client', 'wallet_activity', 'Fri', ['payments' => 2450, 'topup' => 3000], 5],
            ['client', 'wallet_activity', 'Sat', ['payments' => 980, 'topup' => 0], 6],
            ['merchant', 'daily_sales', 'Mon', ['sales' => 284500, 'vat' => 34140], 1],
            ['merchant', 'daily_sales', 'Tue', ['sales' => 312800, 'vat' => 37536], 2],
            ['merchant', 'daily_sales', 'Wed', ['sales' => 198400, 'vat' => 23808], 3],
            ['merchant', 'daily_sales', 'Thu', ['sales' => 421200, 'vat' => 50544], 4],
            ['merchant', 'daily_sales', 'Fri', ['sales' => 567800, 'vat' => 68136], 5],
            ['merchant', 'daily_sales', 'Sat', ['sales' => 689400, 'vat' => 82728], 6],
            ['merchant', 'daily_sales', 'Sun', ['sales' => 445600, 'vat' => 53472], 7],
            ['merchant', 'monthly_sales', 'Jan', ['revenue' => 12400000, 'vat' => 1488000, 'target' => 13000000], 1],
            ['merchant', 'monthly_sales', 'Feb', ['revenue' => 11800000, 'vat' => 1416000, 'target' => 13000000], 2],
            ['merchant', 'monthly_sales', 'Mar', ['revenue' => 14200000, 'vat' => 1704000, 'target' => 13000000], 3],
            ['merchant', 'monthly_sales', 'Apr', ['revenue' => 13600000, 'vat' => 1632000, 'target' => 14000000], 4],
            ['merchant', 'monthly_sales', 'May', ['revenue' => 15800000, 'vat' => 1896000, 'target' => 14000000], 5],
            ['merchant', 'monthly_sales', 'Jun', ['revenue' => 16200000, 'vat' => 1944000, 'target' => 14000000], 6],
        ];

        foreach ($seriesRows as [$portal, $seriesKey, $label, $values, $sort]) {
            DB::table('dashboard_series_points')->insert([
                'portal' => $portal,
                'series_key' => $seriesKey,
                'label' => $label,
                'values' => json_encode($values),
                'sort_order' => $sort,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $listRows = [
            ['super-admin', 'heatmap', 'NCR', 'PHP 1.82B', 'active', null, 98, null, [], 1],
            ['super-admin', 'heatmap', 'IV-A', 'PHP 890M', 'active', null, 81, null, [], 2],
            ['super-admin', 'heatmap', 'III', 'PHP 730M', 'active', null, 74, null, [], 3],
            ['super-admin', 'heatmap', 'VII', 'PHP 510M', 'active', null, 62, null, [], 4],
            ['super-admin', 'compliance_signals', 'EOR Compliance', 'Digital receipt coverage', 'good', 'Receipt', 98.6, null, [], 1],
            ['super-admin', 'compliance_signals', 'MFA Coverage', 'Strong authentication coverage', 'good', 'Fingerprint', 93.4, null, [], 2],
            ['super-admin', 'compliance_signals', 'RBAC Policy Coverage', 'Role policy coverage', 'good', 'Lock', 96.8, null, [], 3],
            ['super-admin', 'compliance_signals', 'Backup Success Rate', 'BCDR backup success', 'good', 'HardDrive', 99.9, null, [], 4],
            ['super-admin', 'ai_signals', 'Potential sales suppression cluster', 'Retail chains in NCR', 'High', 'High', 91, null, [], 1],
            ['super-admin', 'ai_signals', 'Tax filing delay prediction', '42 merchants in Region VII', 'Medium', 'Medium', 73, null, [], 2],
            ['super-admin', 'ai_signals', 'Abnormal refund pattern', 'Hospital billing desks', 'Medium', 'Medium', 69, null, [], 3],
            ['super-admin', 'gateways', 'QRPH', 'QR payments', 'Online', null, 99.98, null, [], 1],
            ['super-admin', 'gateways', 'GCash', 'E-wallet', 'Online', null, 99.94, null, [], 2],
            ['super-admin', 'gateways', 'Maya', 'E-wallet', 'Online', null, 99.91, null, [], 3],
            ['super-admin', 'gateways', 'BancNet', 'Debit / online banking', 'Online', null, 99.87, null, [], 4],
            ['super-admin', 'architecture_controls', 'Laravel API Runtime', 'Current live API backend', 'ready', 'Server', null, null, [], 1],
            ['super-admin', 'architecture_controls', 'MySQL NUERS Database', 'Operational database', 'ready', 'Database', null, null, [], 2],
            ['super-admin', 'architecture_controls', 'RBAC + JWT Token Layer', 'Role-scoped portal access', 'ready', 'ShieldCheck', null, null, [], 3],
            ['super-admin', 'architecture_controls', 'API / Webhook Gateway', 'Merchant and agency integrations', 'ready', 'Network', null, null, [], 4],
            ['super-admin', 'architecture_controls', 'Receipt Verification Portal', 'QR, receipt number, signature', 'ready', 'Globe', null, null, [], 5],
            ['super-admin', 'architecture_controls', 'AI Analytics Layer', 'Fraud, risk, forecasting', 'ready', 'Brain', null, null, [], 6],
            ['super-admin', 'architecture_tags', 'OAuth2 ready', 'Security capability', 'ready', null, null, null, [], 1],
            ['super-admin', 'architecture_tags', 'MFA', 'Security capability', 'ready', null, null, null, [], 2],
            ['super-admin', 'architecture_tags', 'Encryption', 'Security capability', 'ready', null, null, null, [], 3],
            ['super-admin', 'architecture_tags', 'IP restrictions', 'Security capability', 'ready', null, null, null, [], 4],
            ['super-admin', 'architecture_tags', 'Device tracking', 'Security capability', 'ready', null, null, null, [], 5],
            ['super-admin', 'architecture_tags', 'S3 storage ready', 'Infrastructure capability', 'ready', null, null, null, [], 6],
            ['super-admin', 'architecture_tags', 'Kubernetes ready', 'Infrastructure capability', 'ready', null, null, null, [], 7],
            ['super-admin', 'ecosystem_accounts', 'Super Admin Seats', 'RBAC operators with break-glass coverage', 'Operational', 'UserCheck', 42, null, ['display' => '42', 'trend' => '+4 provisioned', 'owner' => 'Platform IAM'], 1],
            ['super-admin', 'ecosystem_accounts', 'BIR Regulator Accounts', 'Regional revenue and audit access', 'Active', 'Landmark', 318, null, ['display' => '318', 'trend' => '21 pending MFA', 'owner' => 'Regulator Admin'], 2],
            ['super-admin', 'ecosystem_accounts', 'Merchant Tenants', 'Businesses connected to NUERS', 'Monitoring', 'Building2', 128420, null, ['display' => '128,420', 'trend' => '2,814 onboarding', 'owner' => 'Merchant Success'], 3],
            ['super-admin', 'ecosystem_accounts', 'Client Accounts', 'Citizens, students, patients, and taxpayers', 'Active', 'Users', 8700000, null, ['display' => '8.7M', 'trend' => '+12.5% adoption', 'owner' => 'Client Identity'], 4],
            ['super-admin', 'live_transactions', 'EOR issuance stream', 'POS, API, kiosk, and cashier receipt creation', 'Good', 'Receipt', 921843, null, ['display' => '921.8K today', 'sla' => 'P95 180ms', 'owner' => 'Receipt Ops'], 1],
            ['super-admin', 'live_transactions', 'Payment authorization rail', 'Cash, QRPH, cards, online banking, and wallets', 'Good', 'CreditCard', 284000, null, ['display' => '284K today', 'sla' => 'P95 240ms', 'owner' => 'Gateway Ops'], 2],
            ['super-admin', 'live_transactions', 'Verification portal hits', 'QR, receipt number, and signature authentication', 'Monitoring', 'Globe', 62400, null, ['display' => '62.4K scans', 'sla' => 'P95 120ms', 'owner' => 'Trust Services'], 3],
            ['super-admin', 'live_transactions', 'Exception reconciliation', 'Failed settlement, voids, refunds, and suspense ledger', 'Warning', 'AlertTriangle', 842, null, ['display' => '842 cases', 'sla' => '12 due today', 'owner' => 'Revenue Assurance'], 4],
            ['super-admin', 'live_transactions', 'Fraud model queue', 'AI anomaly score above investigation threshold', 'High', 'Brain', 37, null, ['display' => '37 high risk', 'sla' => '4 critical', 'owner' => 'AI Audit'], 5],
            ['super-admin', 'security_operations', 'MFA Enforcement', 'Strong authentication coverage across privileged roles', 'Good', 'Fingerprint', 94.2, null, [], 1],
            ['super-admin', 'security_operations', 'RBAC Policy Sync', 'Role permissions synchronized with portal access', 'Good', 'Lock', 97.8, null, [], 2],
            ['super-admin', 'security_operations', 'IP Restriction Coverage', 'Administrative access scoped to trusted networks', 'Monitoring', 'ShieldCheck', 88.5, null, [], 3],
            ['super-admin', 'security_operations', 'Device Trust Score', 'Known device and session trust posture', 'Good', 'Cpu', 91.6, null, [], 4],
            ['super-admin', 'security_operations', 'Session Risk Guard', 'High-risk login and token behavior blocked', 'Good', 'ShieldAlert', 99.1, null, [], 5],
            ['super-admin', 'security_operations', 'Encryption Key Rotation', 'Receipt signing, API, and storage keys rotated', 'Ready', 'Key', 100, null, [], 6],
            ['super-admin', 'api_operations', 'OAuth2 / JWT Authority', 'Token issuance, refresh, and revocation control plane', 'Online', 'Key', 99.97, null, [], 1],
            ['super-admin', 'api_operations', 'API Gateway Uptime', 'Merchant, agency, and verification API edge health', 'Online', 'Network', 99.98, null, [], 2],
            ['super-admin', 'api_operations', 'Webhook Delivery', 'Partner event delivery and retry queue performance', 'Good', 'Zap', 98.7, null, [], 3],
            ['super-admin', 'api_operations', 'Rate Limit Guard', 'Abuse prevention and tenant throttling signals', 'Monitoring', 'Activity', 73, null, [], 4],
            ['super-admin', 'api_operations', 'POS Integration Sync', 'Branch, terminal, and POS heartbeat coverage', 'Good', 'Cpu', 96.3, null, [], 5],
            ['super-admin', 'api_operations', 'Public Verification API', 'Citizen receipt verification service availability', 'Online', 'Globe', 99.99, null, [], 6],
            ['super-admin', 'subscription_governance', 'Enterprise Government Plan', 'NGA, GOCC, SUC, hospital, and LGU deployments', 'Active', 'Landmark', 1482, null, ['display' => '1,482 tenants', 'trend' => '+38 this quarter'], 1],
            ['super-admin', 'subscription_governance', 'Merchant SaaS Subscriptions', 'Registered businesses under paid NUERS plans', 'Active', 'Building2', 126830, null, ['display' => '126,830', 'trend' => '+2.1% month over month'], 2],
            ['super-admin', 'subscription_governance', 'Billing Exceptions', 'Invoices, fees, and plan changes requiring review', 'Review', 'AlertTriangle', 214, null, ['display' => '214 reviews', 'trend' => '32 high priority'], 3],
            ['super-admin', 'subscription_governance', 'Revenue Share Settlements', 'Platform fees, gateway fees, and agency settlement value', 'Operational', 'Wallet', 139400000000, null, ['display' => 'PHP 139.4B', 'trend' => 'Current month'], 4],
            ['super-admin', 'settlement_reconciliation', 'Daily settlement close', 'Bank, wallet, and agency clearing matched', 'Good', 'Wallet', 99.2, null, ['display' => '99.2% matched'], 1],
            ['super-admin', 'settlement_reconciliation', 'Suspense ledger', 'Unmatched payments awaiting revenue assurance', 'Warning', 'FileText', 176, null, ['display' => '176 items'], 2],
            ['super-admin', 'settlement_reconciliation', 'Treasury remittance', 'Collections prepared for fund transfer and reporting', 'Operational', 'Landmark', 98.6, null, ['display' => '98.6% ready'], 3],
            ['super-admin', 'settlement_reconciliation', 'Refund and void controls', 'Cancellation workflow and approval queue', 'Monitoring', 'ShieldCheck', 64, null, ['display' => '64 pending'], 4],
            ['super-admin', 'audit_operations', 'Audit Trail Coverage', 'Login, transaction, receipt, report, and config events', 'Good', 'FileText', 99.9, null, [], 1],
            ['super-admin', 'audit_operations', 'Open Investigation Queue', 'Transaction investigation and exception review cases', 'Review', 'ShieldAlert', 312, null, ['display' => '312 cases'], 2],
            ['super-admin', 'audit_operations', 'Config Change Review', 'System configuration changes pending approval', 'Monitoring', 'Clock', 18, null, ['display' => '18 changes'], 3],
            ['super-admin', 'audit_operations', 'Fraud Escalations', 'Cases escalated from AI risk signals', 'High', 'Brain', 9, null, ['display' => '9 urgent'], 4],
            ['super-admin', 'backup_operations', 'Database Backup Success', 'Automated backup jobs completed successfully', 'Healthy', 'HardDrive', 99.9, null, [], 1],
            ['super-admin', 'backup_operations', 'RPO / RTO Readiness', 'Disaster recovery objectives within operating threshold', 'Operational', 'Server', 98, null, [], 2],
            ['super-admin', 'backup_operations', 'Disaster Recovery Drill', 'Latest continuity exercise and restore validation', 'Ready', 'Database', 96, null, [], 3],
            ['super-admin', 'notification_center', 'Critical Broadcasts', 'System-wide notices for agencies and merchants', 'Active', 'Bell', 24, null, ['display' => '24 active'], 1],
            ['super-admin', 'notification_center', 'Compliance Alerts Sent', 'Automated reminders and non-compliance notices', 'Active', 'AlertTriangle', 18340, null, ['display' => '18,340 sent'], 2],
            ['super-admin', 'notification_center', 'User Inbox Delivery', 'Email, SMS, push, and portal notification delivery rate', 'Good', 'Bell', 99.1, null, [], 3],
            ['super-admin', 'enterprise_modules', 'Merchant Management', 'Registry, onboarding, branch, POS, and status controls', 'Active', 'Building2', null, null, [], 1],
            ['super-admin', 'enterprise_modules', 'BIR Account Management', 'Regulator accounts, regional access, and audit privileges', 'Active', 'Landmark', null, null, [], 2],
            ['super-admin', 'enterprise_modules', 'Client Account Management', 'Citizen, taxpayer, student, and patient account governance', 'Active', 'Users', null, null, [], 3],
            ['super-admin', 'enterprise_modules', 'User and RBAC Administration', 'Roles, permissions, MFA, and policy assignments', 'Active', 'UserCheck', null, null, [], 4],
            ['super-admin', 'enterprise_modules', 'System Configuration', 'Receipt, payment, security, and tenant-wide settings', 'Ready', 'Cpu', null, null, [], 5],
            ['super-admin', 'enterprise_modules', 'Audit Trail Management', 'Traceability for every sensitive platform action', 'Ready', 'FileText', null, null, [], 6],
            ['super-admin', 'enterprise_modules', 'Fraud Detection Monitoring', 'AI anomaly detection and smart audit recommendations', 'Active', 'Brain', null, null, [], 7],
            ['super-admin', 'enterprise_modules', 'Revenue Analytics', 'Forecasting, heatmaps, source mix, and national trends', 'Active', 'BarChart3', null, null, [], 8],
            ['super-admin', 'enterprise_modules', 'Subscription Management', 'Plans, billing exceptions, and platform fee controls', 'Active', 'CreditCard', null, null, [], 9],
            ['super-admin', 'enterprise_modules', 'Payment Gateway Management', 'QRPH, GCash, Maya, cards, banks, and reconciliation', 'Active', 'Wallet', null, null, [], 10],
            ['super-admin', 'enterprise_modules', 'System Health Monitoring', 'Runtime, database, API, webhook, and BCDR telemetry', 'Healthy', 'Server', null, null, [], 11],
            ['super-admin', 'enterprise_modules', 'Notification Center', 'Email, SMS, push, and portal notification operations', 'Active', 'Bell', null, null, [], 12],
            ['bir', 'merchant_compliance', 'ABC Trading Co.', 'NCR - Compliant', 'Compliant', null, 94, null, [], 1],
            ['bir', 'merchant_compliance', 'Quick Mart Chain', 'III - Monitoring', 'Monitoring', null, 88, null, [], 2],
            ['bir', 'merchant_compliance', 'XYZ Enterprises', 'VII - Review', 'Review', null, 67, null, [], 3],
            ['bir', 'audit_queue', 'AUD-2026-1182', 'Receipt cancellation spike', 'High', 'High', null, null, ['scope' => 'Branch POS cluster'], 1],
            ['bir', 'audit_queue', 'AUD-2026-1181', 'Late EOR transmission', 'Medium', 'Medium', null, null, ['scope' => 'Region VII merchants'], 2],
            ['bir', 'audit_queue', 'AUD-2026-1180', 'Signature mismatch', 'High', 'High', null, null, ['scope' => 'Hospital cashier lane'], 3],
            ['bir', 'receipt_verification', 'Authentic receipt', 'Digital signature valid. EOR archived.', 'Issued', 'Verified', null, null, ['receipt_number' => 'OR-2026-0000001', 'merchant' => 'ABC Trading Co.', 'method' => 'QR + signature'], 1],
            ['bir', 'forecast_cards', 'Forecast Confidence', 'AI forecast confidence score', 'active', null, 92, null, [], 1],
            ['bir', 'forecast_cards', 'Collection Gap', 'Potential collection gap', 'active', null, 1400000000, null, ['display' => 'PHP 1.4B'], 2],
            ['bir', 'forecast_cards', 'Actionable Cases', 'Cases requiring action', 'active', null, 312, null, [], 3],
            ['bir', 'reports', 'Daily Reports', 'Generated from collection ledger', 'ready', null, null, null, [], 1],
            ['bir', 'reports', 'Monthly Reports', 'Generated from collection ledger', 'ready', null, null, null, [], 2],
            ['bir', 'reports', 'Quarterly Reports', 'Generated from collection ledger', 'ready', null, null, null, [], 3],
            ['bir', 'reports', 'Annual Reports', 'Generated from collection ledger', 'ready', null, null, null, [], 4],
            ['bir', 'reports', 'CSV Export', 'Export format', 'ready', null, null, null, [], 5],
            ['bir', 'reports', 'Excel Export', 'Export format', 'ready', null, null, null, [], 6],
            ['bir', 'reports', 'PDF Export', 'Export format', 'ready', null, null, null, [], 7],
            ['client', 'transactions', 'PAY-2026-9042', 'Quezon City Treasurer', 'Paid', null, 2450, null, ['type' => 'Business permit renewal', 'date' => 'Jun 2, 2026'], 1],
            ['client', 'transactions', 'PAY-2026-9041', 'State University Cashier', 'Paid', null, 8200, null, ['type' => 'Tuition assessment', 'date' => 'May 30, 2026'], 2],
            ['client', 'transactions', 'PAY-2026-9038', 'Public Hospital Billing', 'Paid', null, 980, null, ['type' => 'Laboratory services', 'date' => 'May 28, 2026'], 3],
            ['client', 'transactions', 'PAY-2026-9029', 'LTO Service Center', 'Pending', null, 630, null, ['type' => 'Regulatory fee', 'date' => 'May 26, 2026'], 4],
            ['client', 'receipts', 'OR-2026-0000001', 'ABC Trading Co.', 'Verified', null, 4850, null, [], 1],
            ['client', 'receipts', 'OR-2026-0000007', 'Public Hospital Billing', 'Verified', null, 980, null, [], 2],
            ['client', 'receipts', 'OR-2026-0000013', 'City Treasurer Office', 'Verified', null, 2450, null, [], 3],
            ['client', 'receipts', 'OR-2026-0000020', 'State University Cashier', 'Verified', null, 8200, null, [], 4],
            ['client', 'notifications', 'Receipt issued', 'ABC Trading Co. issued OR-2026-0000001.', 'Receipt', 'Receipt', null, null, [], 1],
            ['client', 'notifications', 'Payment confirmed', 'Your QC permit renewal payment was posted.', 'Payment', 'Payment', null, null, [], 2],
            ['client', 'notifications', 'Upcoming payment', 'LTO service fee is due in 3 days.', 'Reminder', 'Reminder', null, null, [], 3],
            ['client', 'payment_methods', 'Visa ending 4428', 'Saved card', 'Active', null, null, null, ['icon' => 'CreditCard'], 1],
            ['client', 'payment_methods', 'GCash 0917 **** 184', 'E-wallet', 'Active', null, null, null, ['icon' => 'Smartphone'], 2],
            ['client', 'payment_methods', 'Maya wallet', 'E-wallet', 'Active', null, null, null, ['icon' => 'Smartphone'], 3],
            ['client', 'payment_methods', 'Online banking', 'Bank account', 'Active', null, null, null, ['icon' => 'Smartphone'], 4],
            ['client', 'loyalty', 'Loyalty progress', 'Cashback rewards and loyalty program', 'active', null, 72, null, [], 1],
            ['merchant', 'payment_mix', 'Cash', 'Today', 'active', null, 38, null, ['color' => 'var(--chart-1)'], 1],
            ['merchant', 'payment_mix', 'Credit Card', 'Today', 'active', null, 29, null, ['color' => 'var(--chart-2)'], 2],
            ['merchant', 'payment_mix', 'GCash/Maya', 'Today', 'active', null, 22, null, ['color' => 'var(--chart-3)'], 3],
            ['merchant', 'payment_mix', 'Bank Transfer', 'Today', 'active', null, 11, null, ['color' => 'var(--chart-4)'], 4],
            ['merchant', 'merchant_profile', 'ABC Trading Co.', 'TIN: 123-456-789-000', 'active', 'VAT Registered', 94, null, ['pos_system' => 'Oracle MICROS', 'next_audit_date' => '2026-07-15'], 1],
            ['merchant', 'top_products', 'Electronics', 'This month', 'active', null, 4280000, 87, [], 1],
            ['merchant', 'top_products', 'Appliances', 'This month', 'active', null, 2940000, 60, [], 2],
            ['merchant', 'top_products', 'Clothing', 'This month', 'active', null, 1870000, 38, [], 3],
            ['merchant', 'top_products', 'Food & Bev.', 'This month', 'active', null, 1240000, 25, [], 4],
            ['merchant', 'recent_transactions', 'TXN-2026-109482', '2 min ago', 'completed', 'POS', 4850, null, [], 1],
            ['merchant', 'recent_transactions', 'TXN-2026-109481', '4 min ago', 'completed', 'E-Commerce', 12400, null, [], 2],
            ['merchant', 'recent_transactions', 'TXN-2026-109480', '7 min ago', 'completed', 'POS', 850, null, [], 3],
            ['merchant', 'recent_transactions', 'TXN-2026-109479', '12 min ago', 'completed', 'Corporate', 28700, null, [], 4],
            ['merchant', 'alerts', 'VAT filing due in 5 days - Q2 2026', 'Today', 'warning', 'warning', null, null, [], 1],
            ['merchant', 'alerts', 'New API rate limit policy effective June 1', 'Yesterday', 'info', 'info', null, null, [], 2],
            ['merchant', 'alerts', 'Compliance score improved to 94/100', '2 days ago', 'success', 'success', null, null, [], 3],
            ['merchant', 'integration_health', 'Oracle MICROS', 'POS System', 'Online', null, 99.94, null, [], 1],
            ['merchant', 'integration_health', 'NUERS API', '12ms avg latency', 'Online', null, 99.97, null, [], 2],
            ['merchant', 'integration_health', 'Receipt Engine', 'Operational', 'Online', null, 99.99, null, [], 3],
            ['merchant', 'integration_health', 'BIR Integration', 'Connected', 'Online', null, 99.90, null, [], 4],
        ];

        foreach ($listRows as [$portal, $listKey, $title, $subtitle, $status, $badge, $primary, $secondary, $metadata, $sort]) {
            DB::table('dashboard_list_items')->insert([
                'portal' => $portal,
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

        $taxScopes = ['merchant', 'bir', 'super-admin'];
        DB::table('tax_intelligence_kpis')->whereIn('scope', $taxScopes)->delete();
        DB::table('tax_intelligence_series_points')->whereIn('scope', $taxScopes)->delete();
        DB::table('tax_intelligence_records')->whereIn('scope', $taxScopes)->delete();
        DB::table('tax_graph_edges')->whereIn('scope', $taxScopes)->delete();

        $taxKpis = [
            ['merchant', 'total_sales_invoices', 'Total Sales Invoices', '18,420', 'EOR and B2B invoices issued', 'Receipt', 'border-l-primary', 1],
            ['merchant', 'total_purchase_invoices', 'Total Purchase Invoices', '7,918', 'Supplier invoices captured', 'FileText', 'border-l-chart-3', 2],
            ['merchant', 'output_vat', 'Output VAT', 'PHP 1.94M', 'Seller VAT liability this month', 'TrendingUp', 'border-l-success', 3],
            ['merchant', 'input_vat', 'Input VAT', 'PHP 1.26M', 'Buyer VAT credits available', 'Wallet', 'border-l-chart-2', 4],
            ['merchant', 'vat_payable', 'VAT Payable', 'PHP 680K', 'Net remittance forecast', 'Landmark', 'border-l-warning', 5],
            ['merchant', 'vat_receivable', 'VAT Receivable', 'PHP 142K', 'Validated claim balance', 'ShieldCheck', 'border-l-success', 6],
            ['merchant', 'ewt_withheld', 'EWT Withheld', 'PHP 238K', 'BIR Form 2307 issued', 'ClipboardList', 'border-l-primary', 7],
            ['merchant', 'ewt_receivable', 'EWT Receivable', 'PHP 114K', 'Creditable withholding tax', 'CreditCard', 'border-l-chart-4', 8],
            ['merchant', 'bir_compliance_score', 'BIR Compliance Score', '94%', 'Filing, receipt, VAT, EWT health', 'ShieldCheck', 'border-l-success', 9],
            ['merchant', 'unmatched_transactions', 'Unmatched Transactions', '37', 'Needs reconciliation review', 'AlertTriangle', 'border-l-destructive', 10],
            ['merchant', 'reconciliation_status', 'Reconciliation Status', '96.8%', 'Seller and buyer records matched', 'Activity', 'border-l-success', 11],

            ['bir', 'national_vat_collections', 'National VAT Collections', 'PHP 17.86B', 'Output VAT less validated credits', 'Landmark', 'border-l-primary', 1],
            ['bir', 'national_ewt_collections', 'National EWT Collections', 'PHP 4.82B', 'Expanded withholding tax captured', 'ClipboardList', 'border-l-chart-2', 2],
            ['bir', 'total_b2b_transactions', 'Total B2B Transactions', '12.8M', 'Seller and buyer transaction pairs', 'Activity', 'border-l-success', 3],
            ['bir', 'matched_transactions', 'Matched Transactions', '11.9M', 'Cross-validated invoice pairs', 'ShieldCheck', 'border-l-success', 4],
            ['bir', 'unmatched_transactions', 'Unmatched Transactions', '842K', 'Pending, disputed, or mismatched', 'AlertTriangle', 'border-l-warning', 5],
            ['bir', 'fraud_alerts', 'Fraud Alerts', '1,284', 'AI flagged VAT/EWT anomalies', 'Brain', 'border-l-destructive', 6],
            ['bir', 'compliance_rate', 'Compliance Rate', '96.4%', 'National B2B compliance posture', 'ShieldCheck', 'border-l-success', 7],
            ['bir', 'high_risk_taxpayers', 'High-Risk Taxpayers', '312', 'Red and orange risk taxpayers', 'ShieldAlert', 'border-l-destructive', 8],

            ['super-admin', 'total_b2b_transactions', 'Total B2B Transactions', '58.4M', 'Nationwide B2B transaction graph', 'Activity', 'border-l-primary', 1],
            ['super-admin', 'total_vat_processed', 'Total VAT Processed', 'PHP 139.4B', 'Input and output VAT ledger value', 'Wallet', 'border-l-success', 2],
            ['super-admin', 'total_ewt_processed', 'Total EWT Processed', 'PHP 18.9B', 'EWT and CWT automation value', 'ClipboardList', 'border-l-chart-2', 3],
            ['super-admin', 'active_merchants', 'Active Merchants', '128,420', 'B2B enabled merchant tenants', 'Building2', 'border-l-chart-3', 4],
            ['super-admin', 'active_buyers', 'Active Buyers', '4.2M', 'Enterprise, agency, and taxpayer buyers', 'Users', 'border-l-chart-4', 5],
            ['super-admin', 'transaction_success_rate', 'Transaction Success Rate', '99.2%', 'Matching, validation, and ingestion SLA', 'ShieldCheck', 'border-l-success', 6],
            ['super-admin', 'system_health_status', 'System Health Status', 'Healthy', 'Tax graph, API, AI, and ledger online', 'Server', 'border-l-success', 7],
            ['super-admin', 'ai_fraud_alerts', 'AI Fraud Alerts', '1,284', 'Open tax intelligence cases', 'Brain', 'border-l-destructive', 8],
        ];

        foreach ($taxKpis as [$scope, $key, $label, $value, $subtext, $icon, $accent, $sort]) {
            DB::table('tax_intelligence_kpis')->insert([
                'scope' => $scope,
                'widget_key' => $key,
                'label' => $label,
                'value' => $value,
                'subtext' => $subtext,
                'icon' => $icon,
                'accent' => $accent,
                'sort_order' => $sort,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $taxSeriesRows = [
            ['merchant', 'vat_trend', 'Jan', ['output_vat' => 1.22, 'input_vat' => 0.82, 'vat_payable' => 0.40, 'pending_remittance' => 0.18], 1],
            ['merchant', 'vat_trend', 'Feb', ['output_vat' => 1.34, 'input_vat' => 0.91, 'vat_payable' => 0.43, 'pending_remittance' => 0.21], 2],
            ['merchant', 'vat_trend', 'Mar', ['output_vat' => 1.48, 'input_vat' => 1.04, 'vat_payable' => 0.44, 'pending_remittance' => 0.24], 3],
            ['merchant', 'vat_trend', 'Apr', ['output_vat' => 1.62, 'input_vat' => 1.12, 'vat_payable' => 0.50, 'pending_remittance' => 0.28], 4],
            ['merchant', 'vat_trend', 'May', ['output_vat' => 1.81, 'input_vat' => 1.20, 'vat_payable' => 0.61, 'pending_remittance' => 0.33], 5],
            ['merchant', 'vat_trend', 'Jun', ['output_vat' => 1.94, 'input_vat' => 1.26, 'vat_payable' => 0.68, 'pending_remittance' => 0.36], 6],
            ['merchant', 'ewt_trend', 'Jan', ['ewt_withheld' => 142, 'ewt_receivable' => 72, 'certificates' => 84], 1],
            ['merchant', 'ewt_trend', 'Feb', ['ewt_withheld' => 168, 'ewt_receivable' => 81, 'certificates' => 91], 2],
            ['merchant', 'ewt_trend', 'Mar', ['ewt_withheld' => 174, 'ewt_receivable' => 88, 'certificates' => 106], 3],
            ['merchant', 'ewt_trend', 'Apr', ['ewt_withheld' => 198, 'ewt_receivable' => 102, 'certificates' => 118], 4],
            ['merchant', 'ewt_trend', 'May', ['ewt_withheld' => 226, 'ewt_receivable' => 109, 'certificates' => 132], 5],
            ['merchant', 'ewt_trend', 'Jun', ['ewt_withheld' => 238, 'ewt_receivable' => 114, 'certificates' => 145], 6],
            ['merchant', 'match_status', 'Matched', ['value' => 96.8], 1],
            ['merchant', 'match_status', 'Pending', ['value' => 2.1], 2],
            ['merchant', 'match_status', 'Mismatch', ['value' => 0.8], 3],
            ['merchant', 'match_status', 'BIR Flagged', ['value' => 0.3], 4],

            ['bir', 'national_tax_trend', 'Jan', ['vat' => 12.8, 'ewt' => 3.1, 'b2b' => 8.8, 'fraud_alerts' => 184], 1],
            ['bir', 'national_tax_trend', 'Feb', ['vat' => 13.4, 'ewt' => 3.4, 'b2b' => 9.4, 'fraud_alerts' => 201], 2],
            ['bir', 'national_tax_trend', 'Mar', ['vat' => 14.2, 'ewt' => 3.8, 'b2b' => 10.2, 'fraud_alerts' => 232], 3],
            ['bir', 'national_tax_trend', 'Apr', ['vat' => 15.1, 'ewt' => 4.1, 'b2b' => 11.1, 'fraud_alerts' => 251], 4],
            ['bir', 'national_tax_trend', 'May', ['vat' => 16.5, 'ewt' => 4.5, 'b2b' => 11.8, 'fraud_alerts' => 289], 5],
            ['bir', 'national_tax_trend', 'Jun', ['vat' => 17.9, 'ewt' => 4.8, 'b2b' => 12.8, 'fraud_alerts' => 312], 6],
            ['bir', 'risk_distribution', 'Green', ['taxpayers' => 118240, 'risk' => 12], 1],
            ['bir', 'risk_distribution', 'Yellow', ['taxpayers' => 8120, 'risk' => 42], 2],
            ['bir', 'risk_distribution', 'Orange', ['taxpayers' => 1736, 'risk' => 71], 3],
            ['bir', 'risk_distribution', 'Red', ['taxpayers' => 312, 'risk' => 93], 4],
            ['bir', 'match_status', 'Matched', ['value' => 11900000], 1],
            ['bir', 'match_status', 'Pending', ['value' => 611000], 2],
            ['bir', 'match_status', 'Disputed', ['value' => 196000], 3],
            ['bir', 'match_status', 'High-Risk', ['value' => 35400], 4],

            ['super-admin', 'tax_trend', 'Jan', ['vat' => 98.4, 'ewt' => 12.7, 'forecast' => 104.2], 1],
            ['super-admin', 'tax_trend', 'Feb', ['vat' => 104.8, 'ewt' => 13.9, 'forecast' => 111.4], 2],
            ['super-admin', 'tax_trend', 'Mar', ['vat' => 112.6, 'ewt' => 14.8, 'forecast' => 119.1], 3],
            ['super-admin', 'tax_trend', 'Apr', ['vat' => 121.8, 'ewt' => 16.1, 'forecast' => 128.6], 4],
            ['super-admin', 'tax_trend', 'May', ['vat' => 130.2, 'ewt' => 17.3, 'forecast' => 136.8], 5],
            ['super-admin', 'tax_trend', 'Jun', ['vat' => 139.4, 'ewt' => 18.9, 'forecast' => 145.7], 6],
            ['super-admin', 'model_performance', 'Fraud Model', ['accuracy' => 96.8, 'coverage' => 91.4], 1],
            ['super-admin', 'model_performance', 'VAT Risk Model', ['accuracy' => 94.2, 'coverage' => 88.7], 2],
            ['super-admin', 'model_performance', 'EWT Model', ['accuracy' => 93.1, 'coverage' => 86.9], 3],
            ['super-admin', 'model_performance', 'Graph Model', ['accuracy' => 92.4, 'coverage' => 83.5], 4],
        ];

        foreach ($taxSeriesRows as [$scope, $seriesKey, $label, $values, $sort]) {
            DB::table('tax_intelligence_series_points')->insert([
                'scope' => $scope,
                'series_key' => $seriesKey,
                'label' => $label,
                'values' => json_encode($values),
                'sort_order' => $sort,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $taxRecordRows = [
            ['merchant', 'invoice_matches', 'B2B-INV-2026-8812', 'ABC Trading Co.', 'Metro Supplies Inc.', '456-789-012-000', 2480000, 297600, 49600, 'Matched', 'Low', 96, ['seller_invoice' => 'SI-8812', 'buyer_record' => 'PR-4482', 'variance' => '0.00', 'category' => 'Inventory'], 1],
            ['merchant', 'invoice_matches', 'B2B-INV-2026-8811', 'ABC Trading Co.', 'North Luzon Logistics', '672-184-993-000', 840000, 100800, 16800, 'Pending', 'Medium', 71, ['seller_invoice' => 'SI-8811', 'buyer_record' => 'Waiting buyer confirmation', 'variance' => 'Pending', 'category' => 'Freight'], 2],
            ['merchant', 'invoice_matches', 'B2B-INV-2026-8809', 'ABC Trading Co.', 'Cebu Retail Group', '712-009-882-000', 1260000, 151200, 25200, 'Mismatch', 'High', 84, ['seller_invoice' => 'SI-8809', 'buyer_record' => 'PR-4471', 'variance' => 'PHP 18,200 VAT variance', 'category' => 'Wholesale'], 3],
            ['merchant', 'invoice_matches', 'B2B-INV-2026-8808', 'ABC Trading Co.', 'Davao Institutional Buyers', '112-445-778-000', 620000, 74400, 12400, 'Under Review', 'Medium', 67, ['seller_invoice' => 'SI-8808', 'buyer_record' => 'PR-4465', 'variance' => 'Late buyer validation', 'category' => 'Corporate'], 4],
            ['merchant', 'invoice_matches', 'B2B-INV-2026-8807', 'ABC Trading Co.', 'Manila Office Mart', '309-118-502-000', 312000, 37440, 6240, 'BIR Flagged', 'Critical', 93, ['seller_invoice' => 'SI-8807', 'buyer_record' => 'Duplicate purchase claim', 'variance' => 'Duplicate invoice signal', 'category' => 'Office supplies'], 5],
            ['merchant', 'supplier_compliance', 'SUP-2026-144', 'Metro Supplies Inc.', 'ABC Trading Co.', '456-789-012-000', 2480000, 297600, 49600, 'Compliant', 'Low', 96, ['filing_history' => 'On time', 'receipt_validity' => 'Valid', 'ewt_compliance' => 'Current'], 1],
            ['merchant', 'supplier_compliance', 'SUP-2026-143', 'Cebu Retail Group', 'ABC Trading Co.', '712-009-882-000', 1260000, 151200, 25200, 'Review', 'High', 72, ['filing_history' => 'Late May filing', 'receipt_validity' => 'Valid', 'ewt_compliance' => 'Needs 2307 review'], 2],
            ['merchant', 'supplier_compliance', 'SUP-2026-142', 'Manila Office Mart', 'ABC Trading Co.', '309-118-502-000', 312000, 37440, 6240, 'Flagged', 'Critical', 41, ['filing_history' => 'Missing quarterly filing', 'receipt_validity' => 'Duplicate signal', 'ewt_compliance' => 'Mismatch'], 3],
            ['merchant', 'ewt_certificates', '2307-2026-0901', 'ABC Trading Co.', 'Metro Supplies Inc.', '456-789-012-000', 2480000, 0, 49600, 'Issued', 'Low', 98, ['certificate' => 'BIR Form 2307', 'period' => 'Q2 2026', 'remittance' => 'Scheduled'], 1],
            ['merchant', 'ewt_certificates', '2307-2026-0900', 'ABC Trading Co.', 'North Luzon Logistics', '672-184-993-000', 840000, 0, 16800, 'Pending', 'Medium', 70, ['certificate' => 'BIR Form 2307', 'period' => 'Q2 2026', 'remittance' => 'For approval'], 2],
            ['merchant', 'purchase_ledger', 'PUR-2026-4471', 'ABC Trading Co.', 'Cebu Retail Group', '712-009-882-000', 1260000, 151200, 25200, 'Deductible', 'High', 84, ['expense_category' => 'Inventory', 'claim_status' => 'Pending validation'], 1],
            ['merchant', 'sales_ledger', 'SAL-2026-8812', 'ABC Trading Co.', 'Metro Supplies Inc.', '456-789-012-000', 2480000, 297600, 49600, 'Posted', 'Low', 96, ['aging' => 'Current', 'liability' => 'Output VAT posted'], 1],

            ['bir', 'invoice_matches', 'NIM-2026-1209', 'Quick Mart Chain', 'Metro Supplies Inc.', '456-789-012-000', 18420000, 2210400, 368400, 'Mismatch', 'Red', 94, ['region' => 'NCR', 'industry' => 'Retail', 'variance' => 'Buyer input VAT exceeds seller output VAT'], 1],
            ['bir', 'invoice_matches', 'NIM-2026-1208', 'Cebu Retail Group', 'ABC Trading Co.', '123-456-789-000', 1260000, 151200, 25200, 'Disputed', 'Orange', 82, ['region' => 'VII', 'industry' => 'Wholesale', 'variance' => 'Amount mismatch'], 2],
            ['bir', 'invoice_matches', 'NIM-2026-1207', 'Island Commerce', 'Mindanao Logistics', '671-442-908-000', 9400000, 1128000, 188000, 'Pending', 'Yellow', 63, ['region' => 'XI', 'industry' => 'Logistics', 'variance' => 'Missing seller confirmation'], 3],
            ['bir', 'tax_risks', 'RISK-2026-404', 'Quick Mart Chain', 'Supplier Ring A', '345-678-901-000', 18420000, 2210400, 368400, 'Investigate', 'Red', 94, ['signal' => 'Circular transaction pattern', 'model' => 'Graph fraud model', 'recommendation' => 'Open field audit'], 1],
            ['bir', 'tax_risks', 'RISK-2026-403', 'Manila Office Mart', 'ABC Trading Co.', '309-118-502-000', 312000, 37440, 6240, 'Monitor', 'Orange', 78, ['signal' => 'Duplicate invoice claim', 'model' => 'Duplicate detector', 'recommendation' => 'Request buyer documents'], 2],
            ['bir', 'audit_recommendations', 'AUD-AI-2026-301', 'AI Audit Assistant', 'Quick Mart Chain', '345-678-901-000', 18420000, 2210400, 368400, 'Priority', 'Red', 94, ['next_action' => 'Assign tax fraud investigator', 'basis' => 'VAT mismatch and circular flow'], 1],
            ['bir', 'audit_recommendations', 'AUD-AI-2026-300', 'AI Audit Assistant', 'Cebu Retail Group', '712-009-882-000', 1260000, 151200, 25200, 'Review', 'Orange', 82, ['next_action' => 'Validate supplier books', 'basis' => 'Mismatched amount and late filing'], 2],
            ['bir', 'network_flags', 'NET-2026-017', 'Supplier Ring A', 'Quick Mart Chain', null, 44000000, 5280000, 880000, 'Graph Flagged', 'Red', 96, ['pattern' => 'Circular trade loop', 'nodes' => 12, 'edges' => 31], 1],

            ['super-admin', 'rule_management', 'RULE-VAT-001', 'Output VAT Cross Validation', 'Buyer Input VAT Ledger', null, 0, 0, 0, 'Active', 'Low', 100, ['rule_type' => 'VAT', 'threshold' => '0.5% variance', 'trigger' => 'Immediate mismatch case'], 1],
            ['super-admin', 'rule_management', 'RULE-EWT-002', 'EWT Remittance Monitor', 'BIR Form 2307 Registry', null, 0, 0, 0, 'Active', 'Low', 100, ['rule_type' => 'EWT', 'threshold' => 'Missing certificate after 3 days', 'trigger' => 'Compliance alert'], 2],
            ['super-admin', 'fraud_models', 'MODEL-FRAUD-01', 'VAT Fraud Graph Model', 'Tax Graph Database', null, 0, 0, 0, 'Training', 'Medium', 96, ['version' => 'v4.1', 'coverage' => '91.4%', 'drift' => 'Low'], 1],
            ['super-admin', 'fraud_models', 'MODEL-EWT-02', 'EWT Irregularity Model', 'Withholding Ledger', null, 0, 0, 0, 'Active', 'Low', 93, ['version' => 'v2.7', 'coverage' => '86.9%', 'drift' => 'Medium'], 2],
            ['super-admin', 'reconciliation_monitor', 'REC-2026-9001', 'National Matching Engine', 'B2B Ledger', null, 11900000, 1428000, 238000, 'Matched', 'Low', 99, ['matched' => '11.9M', 'pending' => '611K', 'failed' => '35.4K'], 1],
            ['super-admin', 'reconciliation_monitor', 'REC-2026-9000', 'Suspicious Transactions', 'AI Fraud Queue', null, 35400, 4248000, 708000, 'Suspicious', 'High', 88, ['merchant' => 'Quick Mart Chain', 'region' => 'NCR', 'industry' => 'Retail'], 2],
            ['super-admin', 'tax_analytics', 'ANL-2026-7001', 'Regional VAT Forecast', 'NCR, IV-A, III, VII', null, 139400000000, 13940000000, 1889000000, 'Forecasting', 'Low', 94, ['trend' => '+8.7%', 'confidence' => '94%'], 1],
            ['super-admin', 'tax_analytics', 'ANL-2026-7000', 'Compliance Forecast', 'High-risk taxpayers', null, 0, 0, 0, 'Monitoring', 'Medium', 78, ['forecast' => '312 high risk', 'recommended_action' => 'Tune red threshold'], 2],
        ];

        foreach ($taxRecordRows as [$scope, $recordType, $reference, $party, $counterparty, $tin, $amount, $vat, $withholding, $status, $risk, $score, $metadata, $sort]) {
            DB::table('tax_intelligence_records')->insert([
                'scope' => $scope,
                'record_type' => $recordType,
                'reference' => $reference,
                'party_name' => $party,
                'counterparty_name' => $counterparty,
                'tin' => $tin,
                'amount' => $amount,
                'vat_amount' => $vat,
                'withholding_amount' => $withholding,
                'status' => $status,
                'risk_level' => $risk,
                'score' => $score,
                'metadata' => json_encode($metadata),
                'sort_order' => $sort,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $taxGraphRows = [
            ['merchant', 'ABC Trading Co.', 'Metro Supplies Inc.', 'Supplier invoice match', 'Low', 2480000, ['vat_variance' => '0.00', 'ewt' => 'PHP 49,600'], 1],
            ['merchant', 'ABC Trading Co.', 'Cebu Retail Group', 'Buyer purchase mismatch', 'High', 1260000, ['vat_variance' => 'PHP 18,200', 'status' => 'Mismatch'], 2],
            ['merchant', 'ABC Trading Co.', 'Manila Office Mart', 'Duplicate invoice signal', 'Critical', 312000, ['status' => 'BIR Flagged', 'action' => 'Hold claim'], 3],
            ['bir', 'Quick Mart Chain', 'Supplier Ring A', 'Circular transaction loop', 'Red', 18420000, ['nodes' => 12, 'edges' => 31, 'signal' => 'Invoice factory'], 1],
            ['bir', 'Cebu Retail Group', 'ABC Trading Co.', 'VAT mismatch', 'Orange', 1260000, ['variance' => 'Amount and VAT mismatch'], 2],
            ['bir', 'Island Commerce', 'Mindanao Logistics', 'Missing seller confirmation', 'Yellow', 9400000, ['status' => 'Pending'], 3],
            ['super-admin', 'Tax Graph Database', 'VAT Fraud Graph Model', 'Model feature stream', 'Medium', 58400000, ['coverage' => '83.5%', 'purpose' => 'Network analysis'], 1],
            ['super-admin', 'Merchants', 'Buyers', 'B2B invoice relationships', 'Low', 128420, ['scale' => 'Nationwide'], 2],
            ['super-admin', 'Invoices', 'Tax Filings', 'Cross-validation relationship', 'Low', 11900000, ['matched' => '11.9M'], 3],
            ['super-admin', 'Suspicious Transactions', 'AI Audit Assistant', 'Investigation recommendation', 'High', 35400, ['queue' => 'AI fraud alerts'], 4],
        ];

        foreach ($taxGraphRows as [$scope, $source, $target, $relationship, $risk, $volume, $metadata, $sort]) {
            DB::table('tax_graph_edges')->insert([
                'scope' => $scope,
                'source' => $source,
                'target' => $target,
                'relationship' => $relationship,
                'risk_level' => $risk,
                'volume' => $volume,
                'metadata' => json_encode($metadata),
                'sort_order' => $sort,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
