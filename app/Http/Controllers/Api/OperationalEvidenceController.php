<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OperationalEvidenceController extends Controller
{
    public function show(Request $r)
    {
        app(ApiAccess::class)->require($r, ['admin', 'super_admin', 'bir']);
        $start = microtime(true);
        $db = false;
        try {
            $db = (int) DB::selectOne('SELECT 1 AS healthy')->healthy === 1;
        } catch (\Throwable $e) {
            report($e);
        }
        $checks = [
            ['name' => 'Database query', 'state' => $db ? 'observed' : 'failed', 'detail' => $db ? round((microtime(true) - $start) * 1000, 1).' ms SELECT 1 on the configured application database' : 'Database query failed'],
            ['name' => 'Application storage', 'state' => is_writable(storage_path('app')) ? 'observed' : 'failed', 'detail' => 'Local filesystem permission check; no storage quorum or durability claim'],
            ['name' => 'Shared cache', 'state' => in_array(config('cache.default'), ['redis', 'database'], true) ? 'configured' : 'action_required', 'detail' => 'Configured store: '.config('cache.default').'. Cross-node availability has not been tested.'],
            ['name' => 'Durable queue', 'state' => config('queue.default') !== 'sync' ? 'configured' : 'action_required', 'detail' => 'Configured connection: '.config('queue.default').'. Worker liveness requires deployment monitoring.'],
            ['name' => 'Production debug', 'state' => ! config('app.debug') ? 'configured' : 'action_required', 'detail' => config('app.debug') ? 'Debug is enabled for this local environment. Must be disabled for deployment.' : 'Debug disabled'],
            ['name' => 'BIR transport', 'state' => 'blocked', 'detail' => 'Official interface, authentic-response verifier and taxpayer authorization are required. No live connectivity claim.'],
            ['name' => 'HA, backup restore and DDoS protection', 'state' => 'not_verified', 'detail' => 'Requires provider configuration, restore evidence and authorized drills. Local source code cannot verify edge mitigation or failover.'],
        ];
        $counts = fn ($table) => Schema::hasTable($table) ? DB::table($table)->count() : null;

        return response()->json(['observed_at' => now()->toIso8601String(), 'checks' => $checks,
            'outbox' => Schema::hasTable('eis_document_outbox') ? DB::table('eis_document_outbox')->select('status')->selectRaw('COUNT(*) as total')->groupBy('status')->get() : [],
            'delivery' => Schema::hasTable('eis_delivery_jobs') ? DB::table('eis_delivery_jobs')->select('state')->selectRaw('COUNT(*) as total')->groupBy('state')->get() : [],
            'pending_document_reviews' => Schema::hasTable('document_review_requests') ? DB::table('document_review_requests')->where('status', 'pending')->count() : 0,
            'legal_holds' => $counts('document_legal_holds'), 'queue_jobs' => $counts('jobs'), 'failed_jobs' => $counts('failed_jobs'),
            'notifications' => Schema::hasTable('operational_notifications') ? DB::table('operational_notifications')->whereNull('acknowledged_at')->orderByDesc('created_at')->limit(50)->get() : [],
            'review_requests' => Schema::hasTable('document_review_requests') ? DB::table('document_review_requests')->where('status', 'pending')->orderBy('created_at')->limit(50)->get() : [],
            'identity_claims' => Schema::hasTable('taxpayer_identity_reviews') ? DB::table('taxpayer_identity_reviews')->where('status', 'pending')->orderBy('created_at')->limit(50)->get(['id', 'user_id', 'tin', 'evidence_reference', 'created_at']) : [],
            'external_dependencies' => ['Authorized BIR API package and sandbox access', 'Real PTI/PTT, COR/branch and authorized document-series evidence', 'Independent security assessment and retest', 'Production WAF/origin/network configuration', 'Approved capacity, restore and failover tests'],
        ])->header('Cache-Control', 'private, no-store');
    }
}
