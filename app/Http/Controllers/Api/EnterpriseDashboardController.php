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
        $user = $token ? DB::table('users')->where('api_token', $token)->first() : null;
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
            $merchant = DB::table('merchants')->where('merchant_account_id', $user->id)->first()
                ?? DB::table('merchants')->where(function (Builder $q) use ($user) {
                    $q->where('merchant_account_email', $user->email)->orWhere('email', $user->email);
                })->first();
            $transactions->where('t.merchant_id', $merchant?->id ?? '__unlinked__');
            $receipts->where('r.merchant_id', $merchant?->id ?? '__unlinked__');
            $transmissions->where('merchant_id', $merchant?->id ?? '__unlinked__');
        } elseif ($portal === 'citizen') {
            $account = DB::table('client_accounts')->where('user_id', $user->id)->first()
                ?? DB::table('client_accounts')->where('email', $user->email)->first();
            $tin = $this->tin($profile?->tin ?: ($account?->tin ?? ''));
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
        $birCount = (clone $transmissions)->where('environment', 'production')->whereNotNull('submitted_at')
            ->where(fn ($q) => $q->whereNull('bir_reference_number')->orWhere('bir_reference_number', 'not like', 'CERT-%'))
            ->where(function ($q) {
                $q->where('status', 'submitted')->orWhere(function ($q) {
                    $q->whereIn('status', ['accepted', 'acknowledged', 'processed', 'success'])->whereNotNull('bir_reference_number')
                        ->whereExists(fn ($acks) => $acks->selectRaw('1')->from('eis_acknowledgements as ack')->whereColumn('ack.transmission_id', 'eis_transmissions.id'));
                });
            })->count();
        $metrics = [
            ['label' => 'Total Transactions', 'value' => $count, 'color' => 'blue', 'icon' => 'receipt', 'note' => 'All recorded transactions'],
            ['label' => 'Valid Receipts', 'value' => $validReceipts, 'color' => 'green', 'icon' => 'file-check', 'note' => 'Issued and verified receipts'],
            ['label' => 'Invalid Transactions', 'value' => $invalid, 'color' => 'red', 'icon' => 'file-x', 'note' => 'Rejected, failed or voided'],
        ];
        if ($portal === 'admin') {
            $tins = DB::table('merchants')->whereNotNull('tin')->pluck('tin')
                ->merge(DB::table('client_accounts')->whereNotNull('tin')->pluck('tin'))
                ->merge(DB::table('profiles')->whereNotNull('tin')->pluck('tin'));
            $taxpayers = $tins->map(fn ($tin) => $this->tin($tin))->filter(fn ($tin) => in_array(strlen($tin), [9, 12], true))
                ->map(fn ($tin) => substr($tin, 0, 9))->unique()->count();
            $metrics[] = ['label' => 'Registered Taxpayers', 'value' => $taxpayers, 'color' => 'yellow', 'icon' => 'users', 'note' => 'Distinct registered TINs'];
            $metrics[] = ['label' => 'BIR Submissions', 'value' => $birCount, 'color' => 'purple', 'icon' => 'upload', 'note' => 'Submitted and acknowledged'];
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
        (clone $transactions)->where('t.created_at', '>=', $start->setTimezone($databaseZone)->format('Y-m-d H:i:s'))
            ->where('t.created_at', '<', $end->setTimezone($databaseZone)->format('Y-m-d H:i:s'))
            ->select('t.created_at', 't.status')->orderBy('t.id')->chunk(1000, function ($rows) use (&$buckets, $format, $databaseZone) {
                foreach ($rows as $row) {
                    $key = CarbonImmutable::parse($row->created_at, $databaseZone)->setTimezone('Asia/Manila')->format($format);
                    if (! isset($buckets[$key])) continue;
                    $state = in_array($row->status, self::INVALID, true) ? 'invalid' : (in_array($row->status, self::VALID, true) ? 'valid' : 'pending');
                    $buckets[$key][$state]++;
                }
            });

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
        $checks = $portal === 'admin' ? DB::table('system_health_checks')->get()->keyBy('service_key') : collect();
        $status = $portal === 'admin'
            ? collect([
                'web_servers' => 'Web Servers', 'database_servers' => 'Database Servers',
                'storage_system' => 'Storage System', 'bir_gateway' => 'BIR Gateway',
                'api_gateway' => 'API Services', 'database_backup' => 'Backup System', 'monitoring' => 'Monitoring',
            ])->map(fn ($name, $key) => ['name' => $name, 'status' => $checks->get($key)?->status ?? 'unmonitored', 'checked_at' => $checks->get($key)?->checked_at])->values()
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
