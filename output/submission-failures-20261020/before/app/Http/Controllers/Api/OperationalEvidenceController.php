<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiAccess;
use App\Services\OperationsHealth;
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
            ['name' => 'Software identity', 'state' => 'configured', 'detail' => config('bir_compliance.solution_name').' '.config('bir_compliance.solution_version').'. Deployment identity, not a security certification.'],
            ['name' => 'Privileged MFA', 'state' => config('security.enforce_privileged_mfa') ? 'configured' : 'action_required', 'detail' => config('security.enforce_privileged_mfa') ? 'Required for privileged roles and enrolled accounts; assurance is bound to the current login.' : 'Privileged MFA enforcement is disabled. Enable it before production use.'],
            ['name' => 'API account isolation', 'state' => 'configured', 'detail' => 'Dashboard sessions and machine API keys use separate authentication. Registry and receipt lookups require authorized account scope. This describes application controls; it is not an independent penetration-test result.'],
            ['name' => 'Database query', 'state' => $db ? 'observed' : 'failed', 'detail' => $db ? round((microtime(true) - $start) * 1000, 1).' ms SELECT 1 on the configured application database' : 'Database query failed'],
            ['name' => 'Application storage', 'state' => is_writable(storage_path('app')) ? 'observed' : 'failed', 'detail' => 'Local filesystem permission check; no storage quorum or durability claim'],
            ['name' => 'Shared cache', 'state' => in_array(config('cache.default'), ['redis', 'database'], true) ? 'configured' : 'action_required', 'detail' => 'Configured store: '.config('cache.default').'. Cross-node availability has not been tested.'],
            ['name' => 'Durable queue', 'state' => config('queue.default') !== 'sync' ? 'configured' : 'action_required', 'detail' => 'Configured connection: '.config('queue.default').'. Worker liveness requires deployment monitoring.'],
            ['name' => 'Production debug', 'state' => ! config('app.debug') ? 'configured' : 'action_required', 'detail' => config('app.debug') ? 'Debug is enabled in this environment. Must be disabled for production.' : 'Debug disabled'],
            ['name' => 'BIR transport', 'state' => 'deferred', 'detail' => 'Merchant records are stored in NUERS first. External BIR transmission remains blocked and is excluded from the current storage operations assessment.'],
            ['name' => 'HA, backup restore and DDoS protection', 'state' => 'not_verified', 'detail' => 'Requires provider configuration, restore evidence and authorized drills. Local source code cannot verify edge mitigation or failover.'],
        ];
        $counts = fn ($table) => Schema::hasTable($table) ? DB::table($table)->count() : null;

        return response()->json(['observed_at' => now()->toIso8601String(), 'checks' => $checks, 'storage_operations' => app(OperationsHealth::class)->report(),
            'outbox' => Schema::hasTable('eis_document_outbox') ? DB::table('eis_document_outbox')->select('status')->selectRaw('COUNT(*) as total')->groupBy('status')->get() : [],
            'delivery' => Schema::hasTable('eis_delivery_jobs') ? DB::table('eis_delivery_jobs')->select('state')->selectRaw('COUNT(*) as total')->groupBy('state')->get() : [],
            'pending_document_reviews' => Schema::hasTable('document_review_requests') ? DB::table('document_review_requests')->where('status', 'pending')->count() : 0,
            'legal_holds' => $counts('document_legal_holds'), 'queue_jobs' => $counts('jobs'), 'failed_jobs' => $counts('failed_jobs'),
            'notifications' => Schema::hasTable('operational_notifications') ? DB::table('operational_notifications')->whereNull('acknowledged_at')->orderByDesc('created_at')->limit(50)->get() : [],
            'review_requests' => Schema::hasTable('document_review_requests') ? DB::table('document_review_requests')->where('status', 'pending')->orderBy('created_at')->limit(50)->get() : [],
            'identity_claims' => Schema::hasTable('taxpayer_identity_reviews') ? DB::table('taxpayer_identity_reviews')->where('status', 'pending')->orderBy('created_at')->limit(50)->get(['id', 'user_id', 'tin', 'evidence_reference', 'created_at']) : [],
            'external_dependencies' => ['Independent security assessment and retest', 'Separate encrypted backup storage and independent key custody (not yet provisioned)', 'Production origin/network and external uptime monitoring', 'Production restore/failover and workload capacity tests', 'Merchant-controlled callback receiver for end-to-end delivery verification'],
        ])->header('Cache-Control', 'private, no-store');
    }
}
