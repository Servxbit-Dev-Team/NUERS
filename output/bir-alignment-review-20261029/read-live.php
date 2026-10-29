<?php
$root='/var/www/vhosts/nuers.net/httpdocs';
require $root.'/vendor/autoload.php';$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$a=app(App\Services\BirCompliance::class)->accreditation();
$b=app(App\Services\BackupRecovery::class)->overview(1);
use Illuminate\Support\Facades\DB;
echo json_encode([
 'observed_at'=>now('Asia/Manila')->toIso8601String(),
 'solution_version'=>config('bir_compliance.solution_version'),
 'guards'=>['mfa_enabled'=>(bool)config('security.enforce_privileged_mfa'),'source_intake_accreditation_enforced'=>(bool)config('bir_compliance.source_capture_accreditation_enforced'),'outbound_enabled'=>(bool)config('bir_eis.outbound_enabled'),'callback_enabled'=>(bool)config('bir_eis.callback_enabled'),'service_invoice_issuer_configured'=>(bool)config('bir_compliance.service_invoice_issuer_id')],
 'accreditation'=>['authorized'=>$a['authorized'],'blockers'=>$a['blockers'],'annex_verified'=>count(array_filter($a['annex'],fn($r)=>$r['passed'])),'stages_verified'=>count(array_filter($a['stages'],fn($r)=>$r['passed']))],
 'bir_connections'=>['total'=>DB::table('bir_eis_profiles')->count(),'with_credentials'=>DB::table('bir_eis_profiles')->whereNotNull('encrypted_credentials')->count(),'production_enabled'=>DB::table('bir_eis_profiles')->where('environment','production')->where('enabled',true)->count()],
 'backup'=>['policy_approved'=>$b['policy']['approved'],'controls'=>$b['controls'],'rto_minutes'=>$b['stats']['rto_minutes'],'rpo_minutes'=>$b['stats']['rpo_minutes'],'measurement_current'=>$b['stats']['measurement_current'],'verified_drills'=>$b['drill_summary']['reviewed_passed']]
],JSON_PRETTY_PRINT);
