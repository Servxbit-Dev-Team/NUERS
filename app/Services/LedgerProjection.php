<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Rebuildable, indexed read model. Source records remain authoritative.
 * Database triggers invalidate raw query-builder writes in the same transaction.
 * Readers either catch up a bounded batch or return a retryable 503; never silently serve stale totals.
 */
class LedgerProjection
{
    public const VERSION = 1;
    private static bool $refreshing = false;

    public function ready(): bool
    {
        return !self::$refreshing && Schema::hasTable('ledger_projection_state')
            && (bool) DB::table('ledger_projection_state')->where('id', 1)->where('version', self::VERSION)->value('ready');
    }

    public function query(?string $merchantId = null, bool $refresh = true, bool $includeDrafts = false, bool $canonical = true): Builder
    {
        if ($refresh) {
            $this->catchUp();
        }
        $columns = array_diff(Schema::getColumnListing('ledger_documents'), ['identity_count', 'identity_rank']);
        $q = DB::table('ledger_documents')->select($columns);
        if (!$includeDrafts) {
            $q->where('record_status', '<>', 'draft');
        }
        if ($merchantId !== null) {
            $q->where('merchant_id', $merchantId)->when($canonical, fn ($q) => $q->where('tenant_identity_rank', 1))
                ->selectRaw('tenant_identity_count as identity_count, tenant_identity_rank as identity_rank');
        } else {
            $q->when($canonical, fn ($q) => $q->where('identity_rank', 1))->selectRaw('identity_count,identity_rank');
        }
        return $q;
    }

    public function catchUp(): void
    {
        if (!DB::table('ledger_dirty_documents')->exists()) {
            return;
        }
        $pending = DB::table('ledger_dirty_documents')->limit(1001)->get(['sequence'])->count();
        abort_if($pending > 1000, 503, 'Reports are updating after a large import. Please retry shortly.');
        $this->refresh(1000);
        abort_if(DB::table('ledger_dirty_documents')->exists(), 503, 'Reports are updating. Please retry shortly.');
    }

    /** Use only from the CLI/worker for large backlogs. No source record is mutated. */
    public function refresh(int $limit = 500): int
    {
        $lock = Cache::store(config('cache.default'))->lock('nuers-ledger-projection-v1', 120);
        if (!$lock->get()) {
            return 0;
        }
        self::$refreshing = true;
        try {
            return DB::transaction(function () use ($limit) {
                // Shared database lock serializes rebuilds across web/worker hosts.
                DB::table('ledger_projection_state')->where('id', 1)->lockForUpdate()->first();
                $dirty = DB::table('ledger_dirty_documents')->orderBy('sequence')->limit(min(1000, max(1, $limit)))->lockForUpdate()->get();
                if ($dirty->isEmpty()) {
                    return 0;
                }
                $targets = $dirty->groupBy('source')->map(fn ($rows) => $rows->pluck('document_id')->all())->all();
                $keys = $dirty->map(fn ($r) => $r->source.':'.$r->document_id)->all();
                $old = DB::table('ledger_documents')->whereIn('projection_id', $keys)->get(['identity_key', 'tenant_identity_key']);
                $normalized = app(IssuedDocumentLedger::class)->normalizedCandidates(null, $targets, true);
                if (DB::getDriverName() === 'mysql') {
                    // Match the source database's actual collation, including accents and trailing spaces.
                    $weights = array_map(
                        fn ($c) => "HEX(WEIGHT_STRING(RTRIM($c)))",
                        ["CASE WHEN seller_tin_key='' THEN merchant_id ELSE '' END", 'seller_tin_key', 'seller_branch_key', 'document_family', 'document_number_key']
                    );
                    $normalized = DB::query()->fromSub($normalized, 'n')->select('n.*')
                        ->selectRaw("CONCAT_WS('|',".implode(',', $weights).") as comparison_identity");
                }
                $rows = $normalized->get();
                $financial = [];
                foreach ($rows->whereIn('document_source', ['invoice', 'receipt'])->groupBy('merchant_id') as $merchant => $group) {
                    $financial += app(EisDocumentReadiness::class)->financialBatch($group, (string) $merchant);
                }
                $evidenceRows = Schema::hasColumn('ledger_documents', 'operations_sales_valid')
                    ? app(TransactionTransmissionQuery::class)->enrich(clone $normalized)->get() : collect();
                $reconciliation = Schema::hasColumn('ledger_documents', 'reconciliation_prefix')
                    ? app(ReconciliationEvidence::class)->batch($evidenceRows, $financial) : null;
                $salesEvidence = Schema::hasColumn('ledger_documents', 'operations_sales_valid')
                    ? app(OperationsSalesEvidence::class)->batch($evidenceRows, $financial)
                    : null;
                $written = [];
                foreach ($rows as $row) {
                    $data = (array) $row;
                    $id = $row->document_source.':'.($row->document_id ?: $row->transaction_id);
                    $f = $financial[$id] ?? [];
                    $data['projection_id'] = $id;
                    $data['category'] = $row->status === 'cancelled' ? 'cancelled' : (in_array($row->document_family, ['credit', 'debit'], true) ? 'notes' : (in_array($row->document_family, ['receipt', 'supplementary'], true) ? 'receipts' : 'invoices'));
                    $identity = [$row->seller_tin_key === '' ? $row->merchant_id : '', $row->seller_tin_key, $row->seller_branch_key, $row->document_family, $row->document_number_key];
                    $identityValue = $row->record_status === 'draft' ? 'draft:'.$id : ($row->comparison_identity ?? json_encode($identity, JSON_THROW_ON_ERROR));
                    $data['identity_key'] = hash('sha256', $identityValue);
                    unset($data['comparison_identity']);
                    $data['tenant_identity_key'] = hash('sha256', ($row->merchant_id ?? '').'|'.$data['identity_key']);
                    $data['rank_key'] = ($row->document_source === 'invoice' ? '0:' : '1:').($row->document_id ?: '').':'.$row->id;
                    $data['financial_valid'] = (bool) ($f['financial_validation_passed'] ?? false);
                    if ($salesEvidence !== null) {
                        $data['operations_sales_valid'] = $salesEvidence[$id] ?? false;
                    }
                    if ($reconciliation !== null) {
                        $data += $reconciliation[$id] ?? ['reconciliation_prefix' => null, 'reconciliation_suffix' => null];
                        $data['is_draft'] = $row->record_status === 'draft';
                    }
                    $data['non_vat_centavos'] = collect($f['items'] ?? [])->where('tax_type', 'non_vat')->sum('line_total_centavos');
                    $data['verified_branch'] = $this->verifiedBranch($row, $f);
                    $data['business_day'] = $row->business_date
                        ? ($row->document_source === 'invoice' ? substr($row->business_date, 0, 10) : CarbonImmutable::parse($row->business_date, config('app.timezone'))->setTimezone('Asia/Manila')->toDateString())
                        : null;
                    foreach (['business_date', 'created_at', 'recorded_at'] as $date) {
                        if ($data[$date] && strlen($data[$date]) === 10) {
                            $data[$date] .= ' 00:00:00';
                        }
                    }
                    $data['projected_at'] = now();
                    $written[] = $data;
                }
                // Delete only derivative rows for changed/deleted source IDs, never original records.
                DB::table('ledger_documents')->whereIn('projection_id', $keys)->delete();
                foreach (array_chunk($written, 50) as $batch) {
                    DB::table('ledger_documents')->insert($batch);
                }
                foreach (['identity_key' => ['identity_count', 'identity_rank'], 'tenant_identity_key' => ['tenant_identity_count', 'tenant_identity_rank']] as $key => [$count, $rank]) {
                    $identities = $old->pluck($key)->merge(array_column($written, $key))->unique()->values();
                    // Query only touched identity groups; unrelated history never gets re-ranked.
                    foreach ($identities->chunk(250) as $group) {
                        $members = DB::table('ledger_documents')->whereIn($key, $group)->orderBy('rank_key')->get(['projection_id', $key, $count, $rank])->groupBy($key);
                        foreach ($members as $identity => $docs) {
                            if ($docs->count() === 1 && (int) $docs->first()->$count === 1 && (int) $docs->first()->$rank === 1) {
                                continue;
                            }
                            DB::table('ledger_documents')->where($key, $identity)->update([$count => $docs->count(), $rank => 2]);
                            DB::table('ledger_documents')->where('projection_id', $docs->first()->projection_id)->update([$rank => 1]);
                        }
                    }
                }
                // The rows are locked until commit. A concurrent source write queues a new generation afterwards.
                DB::table('ledger_dirty_documents')->whereIn('sequence', $dirty->pluck('sequence'))->delete();
                DB::table('ledger_projection_state')->where('id', 1)->increment('revision', 1, ['updated_at' => now()]);
                return $dirty->count();
            }, 3);
        } finally {
            self::$refreshing = false;
            $lock->release();
        }
    }

    private function verifiedBranch(object $row, array $financial): ?string
    {
        if (!in_array($row->document_source, ['invoice', 'receipt'], true)) {
            return null;
        }
        $branch = $financial['seller_branch_code'] ?? null;
        if (!$branch) {
            $evidence = app(HistoricalBranchEvidence::class)->latest($row->document_source, $row->document_id);
            if ($evidence && hash_equals($evidence->source_fingerprint, app(HistoricalBranchEvidence::class)->fingerprint($row->document_source, $row->document_id))) {
                $branch = $evidence->branch_code;
            }
        }
        $identity = app(SellerIdentity::class)->parse($financial['merchant_tin'] ?? null, $branch);
        return $identity['valid'] && preg_match('/^\d{5}$/', $identity['branch_code'] ?? '') ? $identity['branch_code'] : null;
    }

    public function enqueueAll(): void
    {
        DB::table('ledger_projection_state')->where('id', 1)->update(['ready' => false]);
        foreach (['receipt' => 'transaction_receipts', 'invoice' => 'business_invoices', 'transaction' => 'merchant_transactions'] as $source => $table) {
            DB::table('ledger_dirty_documents')->insertOrIgnoreUsing(['source', 'document_id'], DB::table($table)->selectRaw("'$source',id"));
        }
        // Removed source rows also need removal when rebuilding a previously initialized projection.
        foreach (['receipt' => 'transaction_receipts', 'invoice' => 'business_invoices', 'transaction' => 'merchant_transactions'] as $source => $table) {
            DB::table('ledger_dirty_documents')->insertOrIgnoreUsing(
                ['source', 'document_id'],
                DB::table('ledger_documents as p')->where('document_source', $source)
                    ->whereNotExists(fn ($q) => $q->selectRaw('1')->from($table.' as s')->whereColumn('s.id', $source === 'transaction' ? 'p.transaction_id' : 'p.document_id'))
                    ->selectRaw("'$source',COALESCE(document_id,transaction_id)")
            );
        }
    }

    public function activate(): void
    {
        if (DB::table('ledger_dirty_documents')->exists()) {
            throw new RuntimeException('Projection backlog remains.');
        }
        DB::table('ledger_projection_state')->where('id', 1)->update(['ready' => true, 'version' => self::VERSION, 'updated_at' => now()]);
    }

    public function installTriggers(): void
    {
        foreach ($this->triggerSpecs() as [$name, $table, $event, $statements]) {
            $body = 'BEGIN '.implode('; ', $statements).'; END';
            $sql = "CREATE TRIGGER $name AFTER $event ON $table FOR EACH ROW $body";
            // Production DBA may install these with the existing application user as
            // DEFINER. Never grant the web application SUPER just to run migrations.
            if (DB::getDriverName() === 'mysql') {
                $existing = DB::table('information_schema.TRIGGERS')
                    ->where('TRIGGER_SCHEMA', DB::getDatabaseName())->where('TRIGGER_NAME', $name)->first();
                if ($existing) {
                    if ($existing->EVENT_OBJECT_TABLE !== $table || $existing->EVENT_MANIPULATION !== $event
                        || $existing->ACTION_TIMING !== 'AFTER'
                        || $this->normalizeTriggerSql($existing->ACTION_STATEMENT) !== $this->normalizeTriggerSql($body)) {
                        throw new RuntimeException("Reporting trigger $name differs from the expected definition.");
                    }
                    continue;
                }
            } else {
                $existing = DB::selectOne("SELECT sql FROM sqlite_master WHERE type='trigger' AND name=?", [$name]);
                if ($existing) {
                    if ($this->normalizeTriggerSql($existing->sql) !== $this->normalizeTriggerSql($sql)) {
                        throw new RuntimeException("Reporting trigger $name differs from the expected definition.");
                    }
                    continue;
                }
            }
            DB::unprepared($sql);
        }
    }

    private function normalizeTriggerSql(string $sql): string
    {
        return strtolower(preg_replace('/\s+/', ' ', trim($sql, " \t\n\r\0\x0B;")));
    }

    public function dropTriggers(): void
    {
        foreach ($this->triggerSpecs() as [$name]) {
            DB::unprepared("DROP TRIGGER IF EXISTS $name");
        }
    }

    private function enqueueSql(string $source, string $expression, ?string $select = null): string
    {
        $selection = $select ?? "SELECT '$source',$expression,1 WHERE $expression IS NOT NULL";
        // MariaDB 10.3 requires FROM DUAL for a constant SELECT with a WHERE clause.
        if (DB::getDriverName() === 'mysql' && !str_contains($selection, ' FROM ')) {
            $selection = str_replace(' WHERE ', ' FROM DUAL WHERE ', $selection);
        }
        $sql = "INSERT INTO ledger_dirty_documents (source,document_id,generation) ".$selection;
        return $sql.(DB::getDriverName() === 'sqlite'
            ? ' ON CONFLICT(source,document_id) DO UPDATE SET generation=generation+1'
            : ' ON DUPLICATE KEY UPDATE generation=generation+1');
    }

    private function triggerSpecs(): array
    {
        $out = [];
        foreach (['transaction_receipts' => 'receipt', 'business_invoices' => 'invoice', 'merchant_transactions' => 'transaction', 'business_invoice_items' => 'items', 'eis_document_branch_reviews' => 'branch'] as $table => $source) {
            foreach (['INSERT' => ['NEW'], 'UPDATE' => ['OLD', 'NEW'], 'DELETE' => ['OLD']] as $event => $refs) {
                $sql = [];
                foreach ($refs as $ref) {
                    if ($source === 'items') {
                        $sql[] = $this->enqueueSql('invoice', "$ref.invoice_id");
                    } elseif ($source === 'branch') {
                        foreach (['invoice', 'receipt'] as $type) {
                            $sql[] = $this->enqueueSql($type, "$ref.document_id", "SELECT '$type',$ref.document_id,1 WHERE $ref.document_source='$type'");
                        }
                    } else {
                        $sql[] = $this->enqueueSql($source, "$ref.id");
                        if ($source === 'transaction') {
                            $sql[] = $this->enqueueSql('receipt', "$ref.receipt_id");
                        }
                        if ($source === 'receipt') {
                            $sql[] = $this->enqueueSql('transaction', '', "SELECT 'transaction',id,1 FROM merchant_transactions WHERE receipt_id=$ref.id AND merchant_id=$ref.merchant_id");
                        }
                    }
                }
                $out[] = ['ledger_'.substr($source, 0, 4).'_'.strtolower($event), $table, $event, $sql];
            }
        }
        // These tables change report labels/status without changing source financial amounts.
        // A coalesced dirty marker participates in the same commit/rollback as the metadata write.
        foreach (['eis_document_outbox' => 'outbox', 'eis_delivery_jobs' => 'delivery', 'merchant_customers' => 'customer', 'merchant_customer_documents' => 'customer_doc', 'eis_transmissions' => 'transmission', 'eis_acknowledgements' => 'ack', 'eis_invoice_payloads' => 'payload'] as $table => $name) {
            foreach (['INSERT', 'UPDATE', 'DELETE'] as $event) {
                $out[] = ['ledger_meta_'.$name.'_'.strtolower($event), $table, $event,
                    [$this->enqueueSql('metadata', "'report-status'")]];
            }
        }
        // Source eligibility includes linked payload evidence. Queue only the affected
        // documents, including both sides of a moved link, in the source transaction.
        foreach (['eis_invoice_payloads' => 'payload', 'eis_transmissions' => 'transmission', 'eis_acknowledgements' => 'ack'] as $table => $name) {
            foreach (['INSERT' => ['NEW'], 'UPDATE' => ['OLD','NEW'], 'DELETE' => ['OLD']] as $event => $refs) {
                $sql = [];
                foreach ($refs as $ref) {
                    $from = $name === 'payload' ? '' : ($name === 'transmission'
                        ? " FROM eis_invoice_payloads p WHERE p.id=$ref.payload_id AND p.merchant_id=$ref.merchant_id"
                        : " FROM eis_transmissions t JOIN eis_invoice_payloads p ON p.id=t.payload_id AND p.merchant_id=t.merchant_id WHERE t.id=$ref.transmission_id");
                    $p = $name === 'payload' ? $ref : 'p';
                    foreach (['receipt' => 'receipt_id', 'invoice' => 'source_document_id'] as $source => $column) {
                        $condition = "$p.$column IS NOT NULL".($source === 'invoice' ? " AND $p.source_document_type='invoice'" : '');
                        $select = "SELECT '$source',$p.$column,1".$from.($from ? ' AND ' : ' WHERE ').$condition;
                        $sql[] = $this->enqueueSql($source, '', $select);
                    }
                }
                $out[] = ['ledger_sales_'.$name.'_'.strtolower($event), $table, $event, $sql];
            }
        }
        // A change to an original invoice also invalidates its linked adjustments.
        foreach (['INSERT' => ['NEW'], 'UPDATE' => ['OLD','NEW'], 'DELETE' => ['OLD']] as $event => $refs) {
            $sql = [];
            foreach ($refs as $ref) {
                $sql[] = $this->enqueueSql('invoice', '', "SELECT 'invoice',id,1 FROM business_invoices WHERE original_invoice_id=$ref.id");
            }
            $out[] = ['ledger_sales_original_'.strtolower($event), 'business_invoices', $event, $sql];
        }
        return $out;
    }
}
