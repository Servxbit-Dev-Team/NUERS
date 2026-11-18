<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\DeviceSession;
use App\Services\Infrastructure\PowerConfirmation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt, DB, Http, Mail};
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class InfrastructurePowerConfirmationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:', 'cache.default'=>'array', 'notifications.dispatch_enabled'=>false, 'infrastructure.enabled'=>false, 'infrastructure.execution_enabled'=>false, 'infrastructure.aws_bridge'=>null, 'security.enforce_privileged_mfa'=>true]);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--force'=>true])->assertSuccessful();
        Http::preventStrayRequests(); Mail::fake();
    }
    private function actor(string $role='super_admin'): array
    {
        $user=User::factory()->create();
        DB::table('profiles')->insert(['id'=>$user->id,'email'=>$user->email,'role'=>$role]);
        $request=Request::create('/api/auth/login','POST');
        $device=(string)Str::uuid();$request->headers->set('X-NUERS-Device',$device);
        $user=app(DeviceSession::class)->issue($user,$request);
        $user->forceFill(['mfa_secret'=>Crypt::encryptString('JBSWY3DPEHPK3PXP'),'api_mfa_verified_at'=>now()])->save();
        return [$user,['Authorization'=>'Bearer '.$user->api_token,'X-NUERS-Device'=>$device]];
    }
    private function node(): object { return DB::table('infra_nodes')->where('code','WEB-01')->first(); }
    private function denied(callable $action): void
    {
        try { $action();$this->fail('Confirmation must be rejected.'); }
        catch(HttpException $e){ $this->assertSame(403,$e->getStatusCode()); }
    }
    public function test_confirmation_is_single_use_and_expires_after_two_minutes(): void
    {
        [$actor]=$this->actor();$node=$this->node();$service=app(PowerConfirmation::class);$id=(string)Str::uuid();
        $proof=$service->issue($actor,$node,'node.reboot',$id);
        $request=['proof_id'=>$proof,'action'=>'node.reboot','idempotency_key'=>$id];
        DB::transaction(fn()=>$service->consume($actor,$node,$request));
        $this->assertNotNull(DB::table('infra_proofs')->where('id',$proof)->value('used_at'));
        $this->denied(fn()=>DB::transaction(fn()=>$service->consume($actor,$node,$request)));
        $request['proof_id']=$service->issue($actor,$node,'node.reboot',$id);
        $this->travel(121)->seconds();
        $this->denied(fn()=>DB::transaction(fn()=>$service->consume($actor,$node,$request)));
    }
    public function test_confirmation_cannot_be_retargeted_or_used_with_a_different_action_or_request(): void
    {
        [$actor]=$this->actor();$node=$this->node();$service=app(PowerConfirmation::class);$id=(string)Str::uuid();
        $request=['proof_id'=>$service->issue($actor,$node,'node.reboot',$id),'action'=>'node.reboot','idempotency_key'=>$id];
        foreach (['id','revision','action','idempotency_key'] as $field) {
            $target=clone $node;$input=$request;
            if($field==='id')$target->id=(string)Str::uuid();
            elseif($field==='revision')$target->revision++;
            elseif($field==='action')$input['action']='node.shutdown';
            else $input['idempotency_key']=(string)Str::uuid();
            $this->denied(fn()=>DB::transaction(fn()=>$service->consume($actor,$target,$input)));
        }
        $this->assertNull(DB::table('infra_proofs')->where('id',$request['proof_id'])->value('used_at'));
    }
    public function test_confirmation_is_bound_to_actor_and_login_session(): void
    {
        [$actor]=$this->actor();[$other]=$this->actor();$node=$this->node();$service=app(PowerConfirmation::class);$id=(string)Str::uuid();
        $input=['proof_id'=>$service->issue($actor,$node,'node.start',$id),'action'=>'node.start','idempotency_key'=>$id];
        $this->denied(fn()=>DB::transaction(fn()=>$service->consume($other,$node,$input)));
        $request=Request::create('/api/auth/login','POST');$request->headers->set('X-NUERS-Device',(string)Str::uuid());
        $replacement=app(DeviceSession::class)->issue($actor,$request);
        $replacement->forceFill(['api_mfa_verified_at'=>now()])->save();
        $this->denied(fn()=>DB::transaction(fn()=>$service->consume($replacement,$node,$input)));
    }
    public function test_power_endpoints_deny_non_super_admin_roles_and_missing_confirmation(): void
    {
        $input=['node_id'=>$this->node()->id,'action'=>'node.start','idempotency_key'=>(string)Str::uuid()];
        foreach(['admin','bir','rdo','merchant','client'] as $role){
            [, $headers]=$this->actor($role);
            $this->postJson('/api/admin/infrastructure/power/prepare',$input,$headers)->assertForbidden();
            $this->postJson('/api/admin/infrastructure/power/confirm',$input,$headers)->assertForbidden();
        }
        [, $headers]=$this->actor();
        $this->postJson('/api/admin/infrastructure/power/confirm',$input,$headers)->assertUnprocessable();
        $this->assertDatabaseCount('infra_actions',0);
    }
    public function test_stale_privileged_mfa_cannot_prepare_or_confirm_power_operations(): void
    {
        [$actor,$headers]=$this->actor();
        $actor->forceFill(['api_authenticated_at'=>now()->subMinutes(10),'api_mfa_verified_at'=>now()->subMinutes(6)])->save();
        $input=['node_id'=>$this->node()->id,'action'=>'node.reboot','idempotency_key'=>(string)Str::uuid()];
        $this->postJson('/api/admin/infrastructure/power/prepare',$input,$headers)->assertForbidden()->assertJsonPath('code','MFA_REQUIRED');
        $this->postJson('/api/admin/infrastructure/power/confirm',$input,$headers)->assertForbidden();
        $this->assertDatabaseCount('infra_proofs',0);$this->assertDatabaseCount('infra_actions',0);
    }
}
