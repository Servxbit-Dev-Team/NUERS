<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EnterpriseDashboardController extends Controller
{
    private const INVALID = ['invalid', 'failed', 'rejected', 'voided', 'cancelled'];
    private const VALID = ['completed', 'valid', 'verified', 'validated', 'success'];
    private const RECEIPTS = ['issued', 'valid', 'verified', 'validated'];

    public function show(Request $request, string $portal): JsonResponse
    {
        abort_unless(in_array($portal, ['admin', 'merchant', 'citizen'], true), 404);
        $token = $request->bearerToken();
        $user = $token ? app(\App\Services\ApiAccess::class)->user($request) : null;
        abort_unless($user, 401, 'Sign in to view your dashboard.');
        $profile = DB::table('profiles')->where('id', $user->id)->first();
        $roles = match ($portal) {
            'admin' => ['admin', 'super_admin'],
            'merchant' => ['merchant', 'admin', 'super_admin'],
            default => ['client', 'consumer', 'admin', 'super_admin'],
        };
        abort_unless(in_array($profile?->role, $roles, true), 403);

        $account = null;
        $merchant = null;
        $transactions = DB::table('merchant_transactions as t');
        $receipts = DB::table('transaction_receipts as r');
        $transmissions = DB::table('eis_transmissions');
        if ($portal === 'merchant') {
            $merchant = app(\App\Services\ApiAccess::class)->merchants($user)->first();
            $transactions->where('t.merchant_id', $merchant?->id ?? '__unlinked__');
            $receipts->where('r.merchant_id', $merchant?->id ?? '__unlinked__');
            $transmissions->where('merchant_id', $merchant?->id ?? '__unlinked__');
        } elseif ($portal === 'citizen') {
            $account = DB::table('client_accounts')->where('user_id', $user->id)->first();
            $tin = $this->tin(app(\App\Services\ApiAccess::class)->taxpayerTin($user));
            if (in_array(strlen($tin), [9, 12], true)) {
                $transactions->whereRaw("REPLACE(REPLACE(t.customer_tin, '-', ''), ' ', '') = ?", [$tin]);
                $receipts->where(function (Builder $q) use ($tin, $transactions) {
                    $q->whereRaw("REPLACE(REPLACE(r.buyer_tin, '-', ''), ' ', '') = ?", [$tin])
                        ->orWhereIn('r.transaction_id', (clone $transactions)->select('t.id'));
                });
            } else {
                $transactions->whereRaw('1 = 0');
                $receipts->whereRaw('1 = 0');
            }
            $transmissions->whereRaw('1 = 0');
        }

        $count = (clone $transactions)->count();
        $validReceipts = (clone $receipts)->whereIn('r.status', self::RECEIPTS)->count();
        $invalid = (clone $transactions)->whereIn('t.status', self::INVALID)->count();
        $birCount=app(\App\Services\SubmissionEvidence::class)->count(clone $transmissions);
        $metrics = [
            ['label' => 'Total Transactions', 'value' => $count, 'color' => 'blue', 'icon' => 'receipt', 'note' => 'All recorded transactions'],
            ['label' => 'Valid Receipts', 'value' => $validReceipts, 'color' => 'green', 'icon' => 'file-check', 'note' => 'Issued and verified receipts'],
            ['label' => 'Invalid Transactions', 'value' => $invalid, 'color' => 'red', 'icon' => 'file-x', 'note' => 'Rejected, failed or voided'],
        ];
        if ($portal === 'admin') {
            $metrics[] = ['label' => 'Registered Merchants', 'value' => DB::table('merchants')->count(), 'color' => 'yellow', 'icon' => 'users', 'note' => 'Businesses in the merchant registry'];
            $metrics[] = ['label' => 'BIR Submissions', 'value' => $birCount, 'color' => 'purple', 'icon' => 'upload', 'note' => 'Authentic production acknowledgements'];
        } elseif ($portal === 'merchant') {
            $metrics[] = ['label' => 'Total Sales', 'value' => (float) (clone $transactions)->whereIn('t.status', self::VALID)->sum('t.amount'), 'format' => 'money', 'color' => 'yellow', 'icon' => 'chart', 'note' => 'Completed transaction amounts'];
            $metrics[] = ['label' => 'BIR Submissions', 'value' => $birCount, 'color' => 'purple', 'icon' => 'upload', 'note' => 'Your submitted transmissions'];
        } else {
            $metrics[2] = ['label' => 'Total Spending', 'value' => (float) (clone $transactions)->whereIn('t.status', self::VALID)->sum('t.amount'), 'format' => 'money', 'color' => 'red', 'icon' => 'chart', 'note' => 'Your completed purchases'];
            $metrics[] = ['label' => 'Receipt Vault', 'value' => (clone $receipts)->count(), 'color' => 'yellow', 'icon' => 'files', 'note' => 'Receipts linked to your TIN'];
            $metrics[] = ['label' => 'Wallet Balance', 'value' => (float) ($account?->wallet_balance ?? 0), 'format' => 'money', 'color' => 'purple', 'icon' => 'wallet', 'note' => 'Your current account balance'];
        }

        $period = $request->query('period', 'today');
        abort_unless(in_array($period, ['today', 'week', 'month', 'year'], true), 422);
        $now = CarbonImmutable::now('Asia/Manila');
        $start = match ($period) {
            'week' => $now->startOfWeek(), 'month' => $now->startOfMonth(),
            'year' => $now->startOfYear(), default => $now->startOfDay(),
        };
        $end = match ($period) {
            'week' => $start->addWeek(), 'month' => $start->addMonth(),
            'year' => $start->addYear(), default => $start->addDay(),
        };
        $format = match ($period) { 'today' => 'H', 'year' => 'm', default => 'Y-m-d' };
        $buckets = [];
        for ($cursor = $start; $cursor->lessThan($end); $cursor = match ($period) {
            'today' => $cursor->addHour(), 'year' => $cursor->addMonth(), default => $cursor->addDay(),
        }) {
            $buckets[$cursor->format($format)] = ['label' => $cursor->format(match ($period) {
                'today' => 'ga', 'week' => 'D', 'year' => 'M', default => 'j',
            }), 'valid' => 0, 'invalid' => 0, 'pending' => 0];
        }
        // NUERS timestamps follow the application's configured database timezone.
        $databaseZone = config('app.timezone', 'UTC');
        $bucket = \App\Services\ReportingTime::bucket('t.created_at', $format);
        $groups = (clone $transactions)->where('t.created_at', '>=', $start->setTimezone($databaseZone)->format('Y-m-d H:i:s'))
            ->where('t.created_at', '<', $end->setTimezone($databaseZone)->format('Y-m-d H:i:s'))
            ->selectRaw("$bucket as bucket,t.status,COUNT(*) as total")->groupByRaw("$bucket,t.status")->get();
        foreach ($groups as $row) {
            if (!isset($buckets[$row->bucket])) continue;
            $state = in_array($row->status, self::INVALID, true) ? 'invalid' : (in_array($row->status, self::VALID, true) ? 'valid' : 'pending');
            $buckets[$row->bucket][$state] += (int) $row->total;
        }

        $regions = (clone $transactions)->select('t.region')->selectRaw('COUNT(*) as total')->groupBy('t.region')->orderByDesc('total')->get();
        $regionRows = $regions->take(8)->map(fn ($r) => ['name' => $r->region ?: 'Unspecified', 'count' => (int) $r->total, 'percentage' => $count ? round($r->total / $count * 100, 1) : 0])->values();
        if ($regions->count() > 8) {
            $other = $regions->slice(8)->sum('total');
            $regionRows->push(['name' => 'Others', 'count' => $other, 'percentage' => round($other / $count * 100, 1)]);
        }
        $recent = (clone $transactions)->leftJoin('merchants as m', 'm.id', '=', 't.merchant_id')
            ->orderByDesc('t.created_at')->limit(5)->get(['t.id', 't.transaction_ref', 't.created_at', 't.amount', 't.status', 'm.tin', 'm.business_name'])
            ->map(function ($row) use ($databaseZone) {
                $row->created_at = CarbonImmutable::parse($row->created_at, $databaseZone)->toIso8601String();
                return $row;
            });
        // Migration seed rows are not health evidence. Report only direct observations or an explicit unknown.
        $status = $portal === 'admin'
            ? collect([
                ['name'=>'Application response','status'=>'online','checked_at'=>$now->toIso8601String()],
                ['name'=>'Database query','status'=>'online','checked_at'=>$now->toIso8601String()],
                ['name'=>'Local storage permissions','status'=>is_writable(storage_path('app'))?'writable':'unavailable','checked_at'=>$now->toIso8601String()],
                ['name'=>'BIR Gateway','status'=>'not_verified','checked_at'=>null],
                ['name'=>'Backup restore','status'=>'not_verified','checked_at'=>null],
                ['name'=>'HA / continuous monitoring','status'=>'not_verified','checked_at'=>null],
            ])
            : collect([
                ['name' => $portal === 'merchant' ? 'Business Account' : 'Citizen Account', 'status' => ($merchant?->status ?? $account?->status) ?: 'unlinked'],
                ['name' => 'Account Profile', 'status' => $profile ? 'linked' : 'unlinked'],
                ['name' => 'TIN Registration', 'status' => in_array(strlen($this->tin($merchant?->tin ?? $profile?->tin ?? $account?->tin ?? '')), [9, 12], true) ? 'bound' : 'pending'],
                ['name' => 'Receipt Vault', 'status' => $validReceipts ? 'available' : 'empty'],
                ['name' => 'Transaction Records', 'status' => $count ? 'available' : 'empty'],
                ['name' => 'Google Sign-in', 'status' => ! empty($user->google_id) ? 'linked' : 'unlinked'],
                ['name' => 'Account Session', 'status' => 'active'],
            ]);
        return response()->json([
            'portal' => $portal, 'metrics' => $metrics, 'volume' => array_values($buckets),
            'financial_summary' => $portal === 'admin' ? app(\App\Services\AdminFinancialSummary::class)->overview(includeWallets: true) : null,
            'regions' => $regionRows, 'recent' => $recent, 'status' => $status,
            'account_active' => strtolower($account?->status ?? '') === 'active',
            'notification_count' => $portal === 'admin' ? DB::table('notifications')->where('is_read', false)->count() : null,
            'updated_at' => $now->toIso8601String(),
        ])->header('Cache-Control', 'private, no-store');
    }

    private function tin(?string $tin): string
    {
        return preg_replace('/[^0-9]/', '', $tin ?? '');
    }
}
