<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

/** Read-only, credential-free evidence of merchant API connections. */
class AdminMerchantConnections
{
    public function overview(array $filters = []): array
    {
        $keys = DB::table('merchant_api_keys')->whereIn('environment', ['live', 'sandbox'])
            ->select('merchant_id', 'environment')->selectRaw('COUNT(*) as total_keys')
            ->selectRaw("SUM(CASE WHEN status = 'active' AND expires_at > ? THEN 1 ELSE 0 END) as active_keys", [now()])
            ->groupBy('merchant_id', 'environment');
        $requests = DB::table('merchant_api_request_logs as l')
            ->join('merchant_api_keys as k', function ($join) {
                $join->on('k.id', '=', 'l.merchant_api_key_id')->on('k.merchant_id', '=', 'l.merchant_id');
            })
            ->where('l.method', 'POST')
            ->whereIn('l.endpoint', ['/api/integrations/transactions', '/api/integrations/transactions/batch'])
            ->select('k.merchant_id', 'k.environment')
            ->selectRaw('MAX(l.created_at) as last_request_at')
            ->selectRaw('MAX(CASE WHEN l.status_code BETWEEN 200 AND 299 THEN l.created_at ELSE NULL END) as last_success_at')
            ->selectRaw('COUNT(*) as requests, SUM(CASE WHEN l.status_code BETWEEN 200 AND 299 THEN 1 ELSE 0 END) as successful_requests')
            ->groupBy('k.merchant_id', 'k.environment');
        $query = DB::query()->fromSub($keys, 'k')->join('merchants as m', 'm.id', '=', 'k.merchant_id')
            ->leftJoinSub($requests, 'r', function ($join) {
                $join->on('r.merchant_id', '=', 'k.merchant_id')->on('r.environment', '=', 'k.environment');
            })
            ->select('m.id as merchant_id', 'm.business_name', 'm.tin', 'm.logo_url', 'm.status as business_status',
                'k.environment', 'k.active_keys', 'k.total_keys', 'r.last_request_at', 'r.last_success_at')
            ->selectRaw('COALESCE(r.requests, 0) as requests, COALESCE(r.successful_requests, 0) as successful_requests')
            ->selectRaw("CASE WHEN m.status <> 'active' THEN 'account_inactive' WHEN k.active_keys = 0 THEN 'no_active_keys' WHEN r.last_success_at IS NOT NULL THEN 'connected' ELSE 'awaiting_connection' END as connection_status");
        if (in_array($filters['environment'] ?? '', ['live', 'sandbox'], true)) {
            $query->where('k.environment', $filters['environment']);
        }
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                // Bind all search input. Normalize formatted TIN searches to match stored digits.
                $q->where('m.business_name', 'like', '%'.$search.'%')->orWhere('m.tin', 'like', '%'.$search.'%');
                $digits = preg_replace('/\D/', '', $search);
                if ($digits !== '' && preg_match('/^[\d\s-]+$/', $search)) {
                    $q->orWhereRaw("REPLACE(m.tin, '-', '') LIKE ?", ['%'.$digits.'%']);
                }
            });
        }
        $rows = DB::query()->fromSub($query, 'connections');
        $summary = (clone $rows)->selectRaw("COUNT(DISTINCT merchant_id) as merchants, COUNT(*) as connections, COUNT(DISTINCT CASE WHEN connection_status = 'connected' THEN merchant_id END) as connected_merchants, COALESCE(SUM(active_keys), 0) as active_keys")->first();
        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? 10)));
        $total = (int) $summary->connections;
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($lastPage, max(1, (int) ($filters['page'] ?? 1)));
        $data = $rows->orderByRaw('CASE WHEN last_success_at IS NULL THEN 1 ELSE 0 END')->orderByDesc('last_success_at')
            ->orderBy('business_name')->orderBy('merchant_id')->orderBy('environment')->forPage($page, $perPage)->get()
            ->map(function ($row) {
                foreach (['active_keys', 'total_keys', 'requests', 'successful_requests'] as $field) $row->$field = (int) $row->$field;
                // Query-builder timestamps follow the application's database write timezone.
                // Send an explicit offset so the browser does not assume they are UTC.
                foreach (['last_request_at', 'last_success_at'] as $field) {
                    if ($row->$field) $row->$field = Carbon::parse($row->$field, config('app.timezone'))->toIso8601String();
                }
                return $row;
            });
        return ['data' => $data, 'summary' => array_map('intval', (array) $summary),
            'pagination' => ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'last_page' => $lastPage],
            'generated_at' => now()->toIso8601String()];
    }
}
