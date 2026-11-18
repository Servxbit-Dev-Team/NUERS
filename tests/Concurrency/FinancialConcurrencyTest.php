<?php

namespace Tests\Concurrency;

use App\Services\MerchantWallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PositiveFlowCase;

/** Explicit MariaDB process races; deliberately excluded from the SQLite default suite. */
class FinancialConcurrencyTest extends PositiveFlowCase
{
    private static ?array $seed = null;

    // Reuse the migrated schema, restoring its exact seed rows between process races.
    // Workers are closed before each reset; only the guarded, disposable schema is touched.
    protected function securityMigrate(): void
    {
        $connection = DB::connection('vapt_migrator');
        if (self::$seed === null) {
            parent::securityMigrate();
            self::$seed = [];
            foreach ($connection->select('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'') as $table) {
                $name = array_values((array) $table)[0];
                self::$seed[$name] = $connection->table($name)->get()->map(fn ($row) => (array) $row)->all();
            }

            return;
        }
        $connection->statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            $connection->transaction(function () use ($connection) {
                foreach (self::$seed as $table => $rows) {
                    $connection->table($table)->delete();
                }
                foreach (self::$seed as $table => $rows) {
                    foreach (array_chunk($rows, 100) as $chunk) {
                        $connection->table($table)->insert($chunk);
                    }
                }
            });
        } finally {
            $connection->statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    protected function setUp(): void
    {
        if (getenv('DB_PORT') !== '3311' || ! getenv('NUERS_TEST_MYSQL_DATABASE')) {
            throw new \RuntimeException('Isolated MariaDB harness required');
        }
        parent::setUp();
        config(['bir_compliance.configuration_reference' => 'synthetic-race-build']);
        DB::table('website_subscription_plans')->where('slug', 'transaction-pricing')->update(['fee_centavos' => 300, 'minimum_transaction_centavos' => 0]);
    }

    private function ready(callable $predicate): void
    {
        $deadline = microtime(true) + 45;
        while (! $predicate() && microtime(true) < $deadline) {
            clearstatcache();
            usleep(10000);
        }$this->assertTrue($predicate(), 'Concurrent worker readiness deadline');
    }

    private function race(array $jobs, ?callable $during = null): array
    {
        $dir = base_path('.local/vapt-races-2026-11-15/barrier-'.Str::uuid());
        File::makeDirectory($dir, 0700, true);
        $running = [];
        try {
            foreach ($jobs as $i => $job) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, base_path('tests/Support/financial-concurrency-worker.php'), $dir, (string) $i], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                fwrite($pipes[0], json_encode($job));
                fclose($pipes[0]);
                $running[] = [$process, $pipes];
            }
            $this->ready(fn () => count(glob($dir.'/ready-*')) === count($jobs));
            touch($dir.'/go');
            if ($during) {
                $this->ready(fn () => is_file($dir.'/paused-0'));
                $during();
                touch($dir.'/resume');
            }
            $result = [];
            foreach ($running as [$process,$pipes]) {
                $output = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $error);
                $row = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
                $this->assertLessThan(500, $row['status'], substr($output, 0, 1200));
                $result[] = $row;
            }
            file_put_contents(base_path('.local/vapt-races-2026-11-15/runs.jsonl'), json_encode(['test' => $this->name(), 'workers' => count($jobs), 'statuses' => array_column($result, 'status'), 'database' => 'isolated-mariadb', 'barrier_synchronized' => true])."\n", FILE_APPEND | LOCK_EX);

            return $result;
        } finally {
            foreach ($running as [$process,$pipes]) {
                if (is_resource($process)) {
                    proc_terminate($process);
                }
            }File::deleteDirectory($dir);
        }
    }

    private function context(int $funds = 3000): array
    {
        [$u,$h] = $this->actor('merchant');
        $m = $this->business($u);
        if ($funds) {
            DB::transaction(fn () => app(MerchantWallet::class)->credit($m, $funds, (string) Str::uuid(), 'Synthetic opening', 'test'));
        }
        $aid = (string) Str::uuid();
        DB::table('esp_accreditations')->insert(['id' => $aid, 'solution_name' => config('bir_compliance.solution_name'), 'solution_version' => config('bir_compliance.solution_version'), 'configuration_reference' => 'synthetic-race-build', 'status' => 'accredited', 'certificate_number' => 'SYN-RACE-NOT-REAL', 'issued_at' => now()->subDay(), 'expires_at' => now()->addYear(), 'annual_review_at' => now()->subDay(), 'evidence_reference' => 'Synthetic evidence only', 'verified_by' => 'synthetic-independent', 'verified_at' => now(), 'created_at' => now()]);
        foreach (['annex', 'stages'] as $c) {
            foreach (config('bir_compliance.'.$c) as $code => $label) {
                DB::table('esp_accreditation_controls')->insert(['id' => (string) Str::uuid(), 'accreditation_id' => $aid, 'category' => $c, 'code' => (string) $code, 'status' => 'passed', 'evidence_reference' => 'Synthetic evidence', 'verified_by' => 'synthetic-independent', 'verified_at' => now()]);
            }
        }
        $i = DB::table('eis_integrations')->first();
        $data = (array) $i;
        unset($data['id']);
        DB::table('eis_integrations')->insert(array_replace($data, ['id' => (string) Str::uuid(), 'merchant_id' => $m, 'environment' => 'production', 'branch_code' => '00000', 'esp_accreditation_id' => $aid, 'pti_status' => 'approved', 'permit_to_issue_number' => 'SYN-PTI-'.$m, 'taxpayer_authorization_reference' => 'Synthetic authority', 'written_authorization_at' => now(), 'created_at' => now(), 'updated_at' => now()]));
        DB::table('taxpayer_authorizations')->insert(['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'merchant_id' => $m, 'kind' => 'PTI', 'reference' => 'SYN-PTI-'.$m, 'environment' => 'production', 'branch_code' => '00000', 'scope' => 'Synthetic scope', 'issued_at' => now()->subDay(), 'valid_until' => now()->addYear(), 'status' => 'active', 'evidence' => 'Synthetic evidence only', 'reason' => 'Synthetic fixture', 'reviewed_by' => 'synthetic-independent', 'reviewed_at' => now()]);
        foreach (['invoice' => 'INV-', 'payment' => 'PAY-'] as $family => $prefix) {
            DB::table('invoice_series')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $m, 'branch_code' => '00000', 'document_family' => $family, 'prefix' => $prefix, 'range_start' => 1, 'range_end' => 100, 'status' => 'active', 'authority_reference' => 'Synthetic authority', 'verified_by' => 'synthetic-independent', 'valid_from' => now()->subDay(), 'valid_until' => now()->addYear(), 'document_registration' => json_encode(['system_type' => 'CAS', 'ac_number' => 'SYNTHETIC-AC', 'authority_issued_at' => now()->subDay()->toDateString()]), 'created_at' => now(), 'updated_at' => now()]);
        }

        return [$u, $h, $m];
    }

    private function invoiceBody(): array
    {
        return ['request_id' => (string) Str::uuid(), 'invoice_number' => 'CLIENT-PROPOSED', 'document_type' => 'sales_invoice', 'buyer_name' => 'Synthetic Buyer', 'buyer_customer_type' => 'retail', 'buyer_email' => 'synthetic@example.test', 'issue_date' => today()->toDateString(), 'status' => 'issued', 'line_items' => [['line_number' => 1, 'description' => 'Synthetic item', 'quantity' => 1, 'unit_price' => 560, 'line_total' => 560, 'tax_type' => 'vatable']]];
    }

    private function job(array $h, array $v, string $url = '/api/business-invoices', string $method = 'POST'): array
    {
        return ['headers' => $h, 'body' => $v, 'url' => $url, 'method' => $method];
    }

    private function statuses(array $rows, array $expected): void
    {
        $actual = array_column($rows, 'status');
        sort($actual);
        sort($expected);
        $this->assertSame($expected, $actual, json_encode($rows));
    }

    private function audit(string $m, int $entries, int $balance): void
    {
        $this->assertSame(['valid' => true, 'entries' => $entries, 'balance_centavos' => $balance], app(MerchantWallet::class)->audit($m));
    }

    public function test_identical_invoice_requests_issue_and_charge_once(): void
    {
        [$u,$h,$m] = $this->context();
        $job = $this->job($h, $this->invoiceBody());
        $r = $this->race(array_fill(0, 4, $job));
        $this->statuses($r, [201, 200, 200, 200]);
        $this->assertCount(1, array_unique(array_map(fn ($x) => $x['body']['invoice']['id'], $r)));
        $this->assertDatabaseCount('business_invoices', 1);
        $this->assertDatabaseCount('invoice_create_requests', 1);
        $this->audit($m, 2, 2700);
    }

    public function test_distinct_invoice_requests_have_unique_monotonic_numbers(): void
    {
        [$u,$h,$m] = $this->context();
        $jobs = [];
        for ($i = 0; $i < 4; $i++) {
            $jobs[] = $this->job($h, $this->invoiceBody());
        }$this->statuses($this->race($jobs), [201, 201, 201, 201]);
        $this->assertSame(['INV-00000001', 'INV-00000002', 'INV-00000003', 'INV-00000004'], DB::table('business_invoices')->orderBy('invoice_number')->pluck('invoice_number')->all());
        $this->audit($m, 5, 1800);
    }

    public function test_same_id_with_conflicting_content_preserves_one_original(): void
    {
        [$u,$h,$m] = $this->context();
        $v = $this->invoiceBody();
        $this->statuses($this->race([$this->job($h, $v), $this->job($h, array_replace($v, ['buyer_name' => 'Different synthetic buyer']))]), [201, 409]);
        $this->assertDatabaseCount('business_invoices', 1);
        $this->audit($m, 2, 2700);
    }

    public function test_last_fee_balance_cannot_be_spent_by_two_invoices(): void
    {
        [$u,$h,$m] = $this->context(300);
        $this->statuses($this->race([$this->job($h, $this->invoiceBody()), $this->job($h, $this->invoiceBody())]), [201, 402]);
        $this->assertDatabaseCount('business_invoices', 1);
        $this->assertEquals(1, DB::table('invoice_series')->where('document_family', 'invoice')->value('last_number'));
        $this->audit($m, 2, 0);
    }

    public function test_simultaneous_draft_publish_charges_and_numbers_once(): void
    {
        [$u,$h,$m] = $this->context();
        $v = array_replace($this->invoiceBody(), ['status' => 'draft']);
        $id = $this->postJson('/api/business-invoices', $v, $h)->assertCreated()->json('invoice.id');
        $j = $this->job($h, ['branch_code' => '00000'], '/api/documents/invoice/'.$id.'/publish');
        $this->statuses($this->race([$j, $j]), [200, 409]);
        $this->assertDatabaseCount('business_invoices', 1);
        $this->audit($m, 2, 2700);
    }

    public function test_invoice_fee_and_withdrawal_share_the_same_balance_lock(): void
    {
        [$u,$h,$m] = $this->context(700);
        $r = $this->race([$this->job($h, $this->invoiceBody()), ['action' => 'withdraw', 'merchant' => $m, 'event' => (string) Str::uuid()]]);
        $this->assertContains(array_column($r, 'status'), [[201, 409], [402, 200]]);
        $issued = $r[0]['status'] === 201;
        $this->assertDatabaseCount('business_invoices', $issued ? 1 : 0);
        $this->audit($m, 2, $issued ? 400 : 0);
    }

    private function funding(): array
    {
        return ['request_id' => (string) Str::uuid(), 'amount_centavos' => 10000, 'payment_method' => 'bank_transfer', 'payment_reference' => 'SYN-'.Str::uuid(), 'payment_date' => today()->toDateString()];
    }

    private function decision(): array
    {
        return ['decision' => 'approved', 'reason' => 'Synthetic settlement verified', 'settlement_reference' => 'SYN-SETTLED-'.Str::uuid(), 'settlement_confirmed' => true];
    }

    public function test_topup_replay_and_concurrent_approval_credit_only_once(): void
    {
        [$u,$h,$m] = $this->context(0);
        $j = $this->job($h, $this->funding(), '/api/merchant/wallet/topups');
        $r = $this->race(array_fill(0, 4, $j));
        $this->statuses($r, [201, 200, 200, 200]);
        $this->assertDatabaseCount('merchant_wallet_entries', 0);
        $id = $r[0]['body']['topup']['id'];
        [$a,$ah] = $this->actor('super_admin');
        $j = $this->job($ah, $this->decision(), '/api/admin/wallets/topups/'.$id.'/review');
        $this->statuses($this->race(array_fill(0, 4, $j)), [200, 409, 409, 409]);
        $this->audit($m, 1, 10000);
    }

    public function test_same_settlement_across_tenants_never_credits_both_wallets(): void
    {
        $jobs = [];
        $merchants = [];
        [$a,$ah] = $this->actor('super_admin');
        $d = $this->decision();
        for ($i = 0; $i < 2; $i++) {
            [$u,$h,$m] = $this->context(0);
            $merchants[] = $m;
            $id = $this->postJson('/api/merchant/wallet/topups', $this->funding(), $h)->assertCreated()->json('topup.id');
            $jobs[] = $this->job($ah, $d, '/api/admin/wallets/topups/'.$id.'/review');
        }$this->statuses($this->race($jobs), [200, 409]);
        $this->assertEquals(10000, DB::table('merchant_wallets')->sum('available_centavos'));
        $this->assertDatabaseCount('merchant_wallet_entries', 1);
        foreach ($merchants as $m) {
            $this->assertTrue(app(MerchantWallet::class)->audit($m)['valid']);
        }
    }

    public function test_distinct_topup_approvals_for_one_wallet_both_commit(): void
    {
        [$u,$h,$m] = $this->context(0);
        [$a,$ah] = $this->actor('super_admin');
        $jobs = [];
        for ($i = 0; $i < 4; $i++) {
            $id = $this->postJson('/api/merchant/wallet/topups', $this->funding(), $h)->assertCreated()->json('topup.id');
            $jobs[] = $this->job($ah, $this->decision(), '/api/admin/wallets/topups/'.$id.'/review');
        }
        $this->statuses($this->race($jobs), [200, 200, 200, 200]);
        $this->audit($m, 4, 40000);
    }

    public function test_simultaneous_wallet_settings_updates_reject_stale_revision(): void
    {
        [$u,$h,$m] = $this->context();
        $revision = (int) DB::table('merchant_wallets')->where('merchant_id', $m)->value('revision');
        $v = ['low_balance_threshold' => 100, 'alerts_enabled' => false, 'intake_mode' => 'batch', 'revision' => $revision];
        $this->statuses($this->race([$this->job($h, $v, '/api/merchant/wallet/settings', 'PUT'), $this->job($h, array_replace($v, ['low_balance_threshold' => 200]), '/api/merchant/wallet/settings', 'PUT')]), [200, 409]);
        $this->assertEquals($revision + 1, DB::table('merchant_wallets')->where('merchant_id', $m)->value('revision'));
        $this->audit($m, 1, 3000);
    }

    public function test_last_invoice_number_is_issued_once_and_failed_charge_rolls_back(): void
    {
        [$u,$h,$m] = $this->context();
        DB::table('invoice_series')->where('merchant_id', $m)->where('document_family', 'invoice')->update(['range_end' => 1]);
        $this->statuses($this->race([$this->job($h, $this->invoiceBody()), $this->job($h, $this->invoiceBody())]), [201, 409]);
        $this->assertDatabaseCount('business_invoices', 1);
        $this->audit($m, 2, 2700);
    }

    private function apiKey(string $m): array
    {
        $raw = Str::random(64);
        $id = (string) Str::uuid();
        DB::table('merchant_api_keys')->insert(['id' => $id, 'merchant_id' => $m, 'name' => 'Synthetic race key', 'environment' => 'live', 'branch_code' => '00000', 'branch_name' => 'Synthetic', 'branch_type' => 'main', 'key_prefix' => 'syn', 'key_last_four' => 'test', 'key_hash' => hash('sha256', $raw), 'scopes' => '["transactions:write","transactions:read"]', 'ip_whitelist' => '[]', 'status' => 'active', 'expires_at' => now()->addDay(), 'rate_limit_per_day' => 10000]);

        return [$id, ['X-NUERS-API-Key' => $raw]];
    }

    private function payment(): array
    {
        return ['schema_version' => 'NUERS-ingestion-1', 'source_system' => 'synthetic-pos', 'source_event_id' => (string) Str::uuid(), 'external_reference' => (string) Str::uuid(), 'event_type' => 'payment.recorded', 'occurred_at' => now()->toIso8601String(), 'branch_code' => '00000', 'receipt_number' => 'PAY-00000001', 'document_type' => 'payment_receipt', 'payment_method' => 'cash', 'gross_amount' => '560.00', 'tax_type' => 'vatable', 'vat_inclusive' => true, 'items' => [['description' => 'Synthetic payment', 'quantity' => 1, 'unit_price' => '560.00', 'tax_type' => 'vatable']]];
    }

    public function test_payment_recording_replay_does_not_duplicate_payment_or_charge_invoice_fee(): void
    {
        [$u,$h,$m] = $this->context(0);
        [$key,$kh] = $this->apiKey($m);
        $j = $this->job($kh, $this->payment(), '/api/integrations/transactions');
        $r = $this->race(array_fill(0, 4, $j));
        $this->statuses($r, [201, 200, 200, 200]);
        $this->assertCount(1, array_unique(array_map(fn ($x) => $x['body']['receipt_id'], $r)));
        foreach (['integration_inbox', 'merchant_transactions', 'transaction_receipts'] as $t) {
            $this->assertDatabaseCount($t, 1);
        }$this->assertDatabaseCount('merchant_wallet_entries', 0);
    }

    public function test_payment_number_collision_with_distinct_events_rejects_second(): void
    {
        [$u,$h,$m] = $this->context(0);
        [$key,$kh] = $this->apiKey($m);
        $this->statuses($this->race([$this->job($kh, $this->payment(), '/api/integrations/transactions'), $this->job($kh, $this->payment(), '/api/integrations/transactions')]), [201, 409]);
        $this->assertDatabaseCount('integration_inbox', 1);
        $this->assertDatabaseCount('merchant_wallet_entries', 0);
    }

    public function test_api_key_revoked_during_request_cannot_record_a_payment(): void
    {
        [$u,$h,$m] = $this->context(0);
        [$key,$kh] = $this->apiKey($m);
        $job = $this->job($kh, $this->payment(), '/api/integrations/transactions') + ['pause_read' => true];
        $this->statuses($this->race([$job], fn () => DB::table('merchant_api_keys')->where('id', $key)->update(['status' => 'revoked'])), [403]);
        $this->assertDatabaseCount('integration_inbox', 0);
    }

    public static function mutationRoutes(): array
    {
        return [['invoice'], ['topup'], ['settings']];
    }

    #[DataProvider('mutationRoutes')]
    public function test_suspension_during_preflight_cannot_write_after_suspension_commits(string $kind): void
    {
        [$u,$h,$m] = $this->context();
        $j = match ($kind) {
            'invoice' => $this->job($h, array_replace($this->invoiceBody(), ['status' => 'draft'])),'topup' => $this->job($h,$this->funding(),'/api/merchant/wallet/topups'),'settings' => $this->job($h,['low_balance_threshold' => 100, 'alerts_enabled' => false, 'intake_mode' => 'batch', 'revision' => 1],'/api/merchant/wallet/settings','PUT')
        };
        $j['pause_read'] = true;
        $r = $this->race([$j],fn () => DB::table('merchants')->where('id',$m)->update(['status' => 'suspended']));
        $this->statuses($r,[403]);
        $this->assertDatabaseCount('business_invoices',0);
        $this->assertDatabaseCount('merchant_wallet_topups',0);
        $this->audit($m,1,3000);
    }
}
