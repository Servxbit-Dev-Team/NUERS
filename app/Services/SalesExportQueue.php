<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Private, tenant/actor-owned, expiring exports. No outbound delivery. */
class SalesExportQueue
{
    public function request(object $merchant, string $actor, array $filters): object
    {
        abort_unless(app(IndexedSalesReport::class)->available(), 503, 'The reporting index is not ready.');
        return DB::transaction(function () use ($merchant, $actor, $filters) {
            DB::table('merchants')->where('id', $merchant->id)->lockForUpdate()->first();
            abort_if(DB::table('sales_report_exports')->where('merchant_id', $merchant->id)->whereIn('state', ['queued', 'running'])->count() >= 2, 429, 'Two exports are already queued or running for this business.');
            $id = (string) Str::uuid();
            DB::table('sales_report_exports')->insert(['id' => $id, 'merchant_id' => $merchant->id, 'actor_id' => $actor, 'filters' => json_encode($filters, JSON_THROW_ON_ERROR), 'state' => 'queued', 'created_at' => now(), 'updated_at' => now(), 'expires_at' => now()->addDay()]);
            app(BirCompliance::class)->audit('merchant.sales_report.export_requested', $id, $actor, ['merchant_id' => $merchant->id, 'filters' => $filters]);
            return DB::table('sales_report_exports')->where('id', $id)->first();
        });
    }

    public function path(string $id): string
    {
        if (!Str::isUuid($id)) {
            throw new RuntimeException('Invalid export ID.');
        }
        return storage_path('app/private/sales-exports/'.$id.'.csv');
    }

    public function runOne(): ?string
    {
        $this->expire();
        $job = DB::transaction(function () {
            $job = DB::table('sales_report_exports')->where('state', 'queued')->where('expires_at', '>', now())->orderBy('created_at')->lockForUpdate()->first();
            if ($job) {
                DB::table('sales_report_exports')->where('id', $job->id)->update(['state' => 'running', 'updated_at' => now()]);
            }
            return $job;
        });
        if (!$job) {
            return null;
        }
        $path = $this->path($job->id);
        $partial = $path.'.partial';
        $handle = null;
        try {
            if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true)) {
                throw new RuntimeException('Export storage unavailable.');
            }
            $merchant = DB::table('merchants')->where('id', $job->merchant_id)->first();
            if (!$merchant) {
                throw new RuntimeException('Business unavailable.');
            }
            app(LedgerProjection::class)->catchUp();
            $handle = fopen($partial, 'xb');
            if (!$handle) {
                throw new RuntimeException('Export file unavailable.');
            }
            chmod($partial, 0600);
            // One consistent snapshot for the totals and every keyset-paginated row.
            if (DB::getDriverName() === 'mysql') {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }
            $result = DB::transaction(function () use ($merchant, $job, $handle) {
                $service = app(IndexedSalesReport::class);
                $filters = json_decode($job->filters, true, 512, JSON_THROW_ON_ERROR);
                // Catch-up completed before this read snapshot; never take reporting write locks for a long export.
                $query = $service->query($merchant, $filters, false, false);
                $report = $service->summary($merchant, $filters, $query);
                $deadline = microtime(true) + 480;
                $detailQuery = $service->query($merchant, $filters, false);
                $rows = (function () use ($query, $detailQuery, $service, $deadline) {
                    $after = '';
                    do {
                        if (microtime(true) > $deadline) {
                            throw new RuntimeException('Export exceeded its time budget. Choose a shorter date range.');
                        }
                        $ids = (clone $query)->select('projection_id')->where('projection_id', '>', $after)->orderBy('projection_id')->limit(1000)->pluck('projection_id');
                        $batch = $ids->isEmpty() ? collect() : (clone $detailQuery)->whereIn('projection_id', $ids)->orderBy('projection_id')->get();
                        foreach ($batch as $row) {
                            $after = $row->projection_id;
                            yield $service->row($row);
                        }
                    } while ($batch->count() === 1000);
                })();
                return [app(SalesReportExport::class)->writeCsv($handle, $report, $rows), $report['snapshot_hash']];
            });
            fclose($handle);
            $handle = null;
            if (!rename($partial, $path)) {
                throw new RuntimeException('Export finalization failed.');
            }
            DB::table('sales_report_exports')->where('id', $job->id)->update(['state' => 'ready', 'records' => $result[0], 'sha256' => hash_file('sha256', $path), 'updated_at' => now()]);
            app(BirCompliance::class)->audit('merchant.sales_report.background_exported', $job->id, $job->actor_id, ['merchant_id' => $job->merchant_id, 'records' => $result[0], 'snapshot_hash' => $result[1]]);
        } catch (Throwable $e) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (is_file($partial)) {
                unlink($partial);
            }
            if (is_file($path)) {
                unlink($path);
            }
            DB::table('sales_report_exports')->where('id', $job->id)->update(['state' => 'failed', 'error' => 'The export could not finish. Retry with a shorter date range or contact support.', 'updated_at' => now()]);
            report($e);
        }
        return $job->id;
    }

    public function expire(): void
    {
        DB::table('sales_report_exports')->where('state', 'running')->where('updated_at', '<', now()->subMinutes(15))->update(['state' => 'failed', 'error' => 'The export worker was interrupted. Please request a new export.', 'updated_at' => now()]);
        foreach (DB::table('sales_report_exports')->where('expires_at', '<=', now())->where('state', '!=', 'expired')->limit(100)->get() as $job) {
            foreach ([$this->path($job->id), $this->path($job->id).'.partial'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            DB::table('sales_report_exports')->where('id', $job->id)->update(['state' => 'expired', 'updated_at' => now()]);
        }
    }
}
