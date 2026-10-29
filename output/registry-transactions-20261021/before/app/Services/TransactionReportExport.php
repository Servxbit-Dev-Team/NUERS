<?php

namespace App\Services;

use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** One export contract for the authorized, filtered Live or Sandbox population. */
class TransactionReportExport
{
    public const PDF_LIMIT = 500;

    public const COLUMNS = [
        'intake_environment'=>'Record Environment', 'id'=>'Record ID', 'merchant_id'=>'Merchant ID',
        'business'=>'Business', 'tin'=>'Seller TIN', 'seller_address'=>'Seller Address',
        'transaction_ref'=>'Transaction Reference', 'receipt_number'=>'Document Number',
        'business_date'=>'Original Document Date', 'recorded_at'=>'Received At (ISO 8601)',
        'document_type'=>'Document Type', 'document_source'=>'Record Source', 'document_id'=>'Source Document ID',
        'status'=>'NUERS Status', 'record_status'=>'Original Record Status', 'sale_category'=>'Sale Category',
        'customer_name'=>'Customer', 'customer_tin'=>'Customer TIN', 'branch'=>'Branch', 'branch_code'=>'Branch Code',
        'region'=>'Region', 'rdo_code'=>'RDO', 'channel'=>'Channel', 'payment_method'=>'Representative Payment Method',
        'amount'=>'Document Amount PHP', 'vatable_sales'=>'VATable Base PHP', 'vat_amount'=>'VAT PHP',
        'vat_exempt_sales'=>'Recorded Exempt Base PHP', 'zero_rated_sales'=>'Zero-rated Base PHP', 'sspt_sales'=>'Recorded SSPT Base PHP',
        'net_amount'=>'Recorded Net Base PHP', 'manual_discount_amount'=>'Manual Discount PHP',
        'statutory_discount_amount'=>'Statutory Discount PHP', 'vat_relief_amount'=>'VAT Adjustment PHP', 'discount_amount'=>'Total Discount PHP',
        'payment_count'=>'Linked Payment Events', 'payment_amount'=>'Linked Payment Amount PHP',
        'source_record_count'=>'Identity Source Count', 'bir_status'=>'BIR Evidence Status',
        'transmission_id'=>'Transmission ID', 'environment'=>'Transmission Environment',
        'acknowledgement_number'=>'Acknowledgment Number', 'bir_reference_number'=>'BIR Reference',
        'response_code'=>'Response Code', 'response_message'=>'Response Message',
    ];

    public function download($query, array $filters, object $actor, string $environment, callable $normalize)
    {
        $format = $filters['format'] ?? 'csv';
        $store = Cache::store(config('cache.default') === 'array' ? 'database' : config('cache.default'));
        $key = 'transaction-export:'.hash('sha256', (string) $actor->id);
        $limiter = new RateLimiter($store);
        abort_if($limiter->tooManyAttempts($key, 5), 429, 'Please wait a minute before requesting another transaction export.');
        $limiter->hit($key, 60);
        $filename = 'NUERS-Transactions-'.ucfirst($environment).'-'.now('Asia/Manila')->format('Ymd-His');
        $headers = ['Cache-Control'=>'private, no-store', 'X-Content-Type-Options'=>'nosniff'];
        if ($format === 'csv') {
            app(BirCompliance::class)->audit('transactions.export.requested', 'transaction-workspace', (string) $actor->id, ['environment'=>$environment, 'format'=>'csv', 'filters'=>$filters]);
            return response()->streamDownload(function () use ($query, $normalize) {
                $file = fopen('php://output', 'w'); fwrite($file, "\xEF\xBB\xBF");
                fputcsv($file, array_values(self::COLUMNS), escape: '');
                foreach ($query->cursor() as $source) {
                    $row = $normalize($source);
                    $values = [];
                    foreach (self::COLUMNS as $key=>$label) {
                        $value = $row[$key] ?? '';
                        if (str_ends_with($label, ' PHP') && is_numeric($value)) $value = number_format((float)$value, 2, '.', '');
                        elseif (is_string($value) && preg_match('/^[\s\x00-\x1f]*[=+@\-]/u', $value)) $value = "'".$value;
                        $values[] = $value;
                    }
                    fputcsv($file, $values, escape: '');
                }
                fclose($file);
            }, $filename.'.csv', [...$headers, 'Content-Type'=>'text/csv; charset=UTF-8']);
        }
        $lock = $store->lock($key.':pdf', 90);
        abort_unless($lock->get(), 429, 'Another PDF export is running for your account. Please wait.');
        try {
            // Read one bounded result set. Totals and every page are computed from these exact rows.
            $sources = (clone $query)->limit(self::PDF_LIMIT + 1)->get();
            abort_if($sources->count() > self::PDF_LIMIT, 422, 'Detailed PDF supports up to '.self::PDF_LIMIT.' matching records. Narrow the date/merchant filters, select rows, or export all matching records as CSV. No records were exported.');
            $rows = $sources->map($normalize)->all();
            $report = $this->report($rows, $filters, $actor, $environment);
            return app(TransactionPdfQueue::class)->request($report, $filename, $actor);
        } finally { $lock->release(); }
    }

    public function report(array $rows, array $filters, object $actor, string $environment): array
    {
        unset($filters['page'], $filters['per_page'], $filters['format']);
        $totals = $this->totals($rows);
        $merchants = collect($rows)->groupBy('merchant_id')->map(fn ($group) => [
            'id'=>$group->first()['merchant_id'], 'name'=>$group->first()['business'] ?? 'Not recorded',
            'tin'=>$group->first()['tin'] ?? '', ...$this->totals($group->all()),
        ])->values()->all();
        $categories = collect($rows)->groupBy('sale_category')->map(fn ($group, $category) => ['category'=>$category, ...$this->totals($group->all())])->values()->all();
        return ['id'=>'TXR-'.Str::upper(Str::random(12)), 'generated_at'=>now('Asia/Manila')->format('M d, Y H:i:s').' PHT',
            'actor'=>(string)($actor->name ?? $actor->id), 'version'=>config('bir_compliance.solution_version', '1.0.0'),
            'environment'=>$environment, 'filters'=>$filters, 'rows'=>$rows, 'totals'=>$totals, 'merchants'=>$merchants,
            'categories'=>$categories, 'hash'=>hash('sha256', json_encode([$environment,$filters,$rows], JSON_THROW_ON_ERROR)),
        ];
    }

    private function totals(array $rows): array
    {
        $keys = ['amount','vatable_sales','vat_amount','vat_exempt_sales','zero_rated_sales','sspt_sales','net_amount','manual_discount_amount','statutory_discount_amount','discount_amount','vat_relief_amount','payment_amount'];
        $totals = array_fill_keys($keys, 0) + ['count'=>count($rows), 'success'=>0, 'failed'=>0, 'pending'=>0, 'cancelled'=>0, 'conflicts'=>0];
        foreach ($rows as $row) {
            foreach ($keys as $key) $totals[$key] += (int) round((float)($row[$key] ?? 0) * 100);
            $status = $row['status'] ?? 'pending'; if (in_array($status, ['success','failed','pending','cancelled'], true)) $totals[$status]++;
            if (($row['source_record_count'] ?? 1) > 1) $totals['conflicts']++;
        }
        return $totals;
    }
}
