<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiAccess;
use App\Services\SubmissionAttempts;
use Illuminate\Http\Request;

class SubmissionAttemptsController extends Controller
{
    public function index(Request $r)
    {
        app(ApiAccess::class)->require($r, ['admin', 'super_admin']);
        $v = $r->validate([
            'environment' => 'sometimes|in:live,sandbox', 'search' => 'nullable|string|max:120',
            'status' => 'nullable|integer|min:400|max:599', 'page' => 'sometimes|integer|min:1|max:100000',
        ]);
        $environment = $v['environment'] ?? 'live';
        $q = app(SubmissionAttempts::class)->failures($environment);
        if ($v['status'] ?? null) {
            $q->where('l.status_code', $v['status']);
        }
        if ($search = trim($v['search'] ?? '')) {
            $q->where(function ($q) use ($search) {
                foreach (['m.business_name', 'm.tin', 'l.request_reference', 'l.source_event_id', 'l.source_system'] as $field) {
                    $q->orWhere($field, 'like', '%'.addcslashes($search, '%_\\').'%');
                }
                if (preg_match('/^[\d\s-]+$/', $search) && ($digits = preg_replace('/\D/', '', $search))) {
                    $q->orWhereRaw("REPLACE(REPLACE(m.tin, '-', ''), ' ', '') LIKE ?", ['%'.$digits.'%']);
                }
            });
        }
        $rows = $q->select(['l.id', 'l.created_at', 'l.status_code', 'l.request_reference', 'l.source_event_id',
            'l.source_system', 'l.endpoint', 'l.error_message', 'l.error_code', 'l.outcome', 'l.branch_code',
            'm.business_name', 'm.tin'])
            ->orderByDesc('l.created_at')->orderByDesc('l.id')->paginate(15);
        $rows->through(function ($row) {
            if ($row->created_at) {
                $row->created_at = \Carbon\CarbonImmutable::parse($row->created_at, config('app.timezone'))->toIso8601String();
            }
            return $row;
        });
        return response()->json(['environment' => $environment, 'attempts' => $rows]);
    }
}
