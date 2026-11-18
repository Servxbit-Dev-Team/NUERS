<?php
namespace Tests\Feature;
use Tests\Support\PositiveFlowCase;
use Illuminate\Support\Facades\{DB,Http};
use Illuminate\Support\Str;
use App\Services\Infrastructure\{Providers,ControllerGateway,AwsPowerController,AwsBackupGateway};
class PositiveInfrastructureFlowsTest extends PositiveFlowCase
{
    private function node(bool $backup=false):object {
        $n=DB::table('infra_nodes')->where('code','WEB-01')->first();
        DB::table('infra_nodes')->where('id',$n->id)->update(['provider'=>'aws','resource_type'=>$backup?'managed_database':'virtual_machine','provider_reference'=>'synthetic-local-resource','region'=>'ap-southeast-1','network_zone'=>'private','environment'=>'staging','tier'=>$backup?'database':'web','ha_group'=>'synthetic-private','enrollment'=>'enrolled','fingerprint'=>str_repeat('a',64),'observed_at'=>now('UTC'),'telemetry'=>json_encode(['resource_state'=>'stopped','healthy'=>true,'reachable'=>true])]);
        $n=DB::table('infra_nodes')->find($n->id);
        config(['infrastructure.node_pins.'.$n->code=>Providers::identity($n)+['fingerprint'=>$n->fingerprint,'environment'=>$n->environment,'tier'=>$n->tier,'ha_group'=>$n->ha_group], 'infrastructure.backup_nodes'=>[$n->code],'infrastructure.aws_power_nodes'=>[$n->code],'infrastructure.freshness_seconds'=>120,'infrastructure.driver'=>'aws_power','infrastructure.execution_enabled'=>true,'infrastructure.capabilities'=>['node.start']]);return $n;
    }
    public function test_power_prepare_confirm_and_idempotency_with_every_role():void {
        $this->mock(ControllerGateway::class,fn($m)=>$m->shouldReceive('ready')->andReturn(true));
        $this->mock(AwsPowerController::class,fn($m)=>$m->shouldReceive('inspect')->andReturn(['passed'=>true,'checks'=>[['label'=>'Synthetic cloud response','passed'=>true]]]));
        $this->allRoles(function($role,$u,$h){$n=$this->node();$v=['node_id'=>$n->id,'action'=>'node.start','idempotency_key'=>(string)Str::uuid()];
            $p=$this->checked($this->postJson('/api/admin/infrastructure/power/prepare',$v,$h),$role,['super_admin']);
            if($role==='super_admin'){$p->assertJsonPath('preflight.passed',true);$this->assertNotNull($p->json('confirmation_id'));}
            $v+=['confirmation_id'=>$p->json('confirmation_id')??(string)Str::uuid(),'confirmed'=>true];
            $r=$this->checked($this->postJson('/api/admin/infrastructure/power/confirm',$v,$h),$role,['super_admin'],201);
            if($role==='super_admin'){$r->assertJsonPath('data.state','scheduled');$this->postJson('/api/admin/infrastructure/power/confirm',$v,$h)->assertStatus(201)->assertJsonPath('data.id',$r->json('data.id'));}
            $this->assertDatabaseCount('infra_actions',$role==='super_admin'?1:0);
        });Http::assertNothingSent();
    }
    public function test_backup_prepare_confirm_and_single_dispatch_with_every_role():void {
        $this->mock(AwsBackupGateway::class,function($m){$m->shouldReceive('ready')->andReturn(true);$m->shouldReceive('start')->once()->andReturn(['job_id'=>'synthetic-backup-job']);});
        $this->allRoles(function($role,$u,$h){$n=$this->node(true);$v=['node_id'=>$n->id,'request_id'=>(string)Str::uuid()];
            $p=$this->checked($this->postJson('/api/admin/infrastructure/backups/prepare',$v,$h),$role,['super_admin']);
            $v+=['confirmation_id'=>$p->json('confirmation_id')??(string)Str::uuid(),'confirmed'=>true];
            $r=$this->checked($this->postJson('/api/admin/infrastructure/backups/confirm',$v,$h),$role,['super_admin'],202);
            if($role==='super_admin'){$r->assertJsonPath('job_id','synthetic-backup-job');$this->postJson('/api/admin/infrastructure/backups/confirm',$v,$h)->assertOk()->assertJsonPath('job_id','synthetic-backup-job');$this->assertDatabaseHas('bcdr_requests',['status'=>'accepted','request_id'=>$v['request_id']]);}
            $this->assertDatabaseCount('bcdr_requests',$role==='super_admin'?1:0);
        });Http::assertNothingSent();
    }
    public function test_expansion_records_request_without_purchasing_for_every_role():void {
        $this->allRoles(function($role,$u,$h){$proof=(string)Str::uuid();DB::table('infra_proofs')->insert(['id'=>$proof,'actor_id'=>(string)$u->id,'binding'=>app(\App\Services\MfaDevices::class)->binding($u),'expires_at'=>now('UTC')->addMinutes(2)]);
            $v=['provider'=>'aws','resource_type'=>'virtual_machine','tier'=>'web','environment'=>'staging','region'=>'ap-southeast-1','network_zone'=>'private','quantity'=>1,'vcpu'=>2,'memory_gib'=>4,'storage_gib'=>30,'estimated_monthly_php'=>1000,'reason'=>'Synthetic expansion request only','proof_id'=>$proof,'idempotency_key'=>(string)Str::uuid()];
            $r=$this->checked($this->postJson('/api/admin/infrastructure/expansions',$v,$h),$role,['super_admin'],201);
            if($role==='super_admin'){$r->assertJsonPath('data.state','awaiting_review')->assertJsonPath('data.execution_enabled',false);$this->postJson('/api/admin/infrastructure/expansions',$v,$h)->assertCreated()->assertJsonPath('data.id',$r->json('data.id'));}
            $this->assertDatabaseCount('infra_expansion_requests',$role==='super_admin'?1:0);$this->assertDatabaseCount('infra_actions',0);
        });Http::assertNothingSent();
    }
}
