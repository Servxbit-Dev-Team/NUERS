<?php
namespace Tests\Feature;
use Tests\Support\PositiveFlowCase;
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

class ReadinessAssuranceBoundaryTest extends PositiveFlowCase
{
    public static function googleCases():array {
        $cases=[];foreach(self::ROLES as $role)foreach(['unenrolled','opted_out','enrolled']as$state)$cases["$role-$state"]=[$role,$state];return $cases;
    }
    #[DataProvider('googleCases')]
    public function test_google_exchange_obeys_shared_mfa_policy_without_changing_enrollment(string $role,string $state):void {
        [$u,$h]=$this->actor($role);$secret=$state==='enrolled'?Crypt::encryptString('JBSWY3DPEHPK3PXP'):null;
        $u->forceFill(['google_id'=>'synthetic-google-'.$u->id,'mfa_secret'=>$secret,'mfa_disabled_at'=>$state==='opted_out'?now():null])->save();
        $pending=['user_id'=>$u->id,'expires_at'=>now()->addMinutes(5)->timestamp,'credential_stamp'=>hash('sha256',$u->password),'registered_email'=>strtolower($u->email),'google_subject'=>$u->google_id];
        $r=$this->withSession(['google_login'=>$pending])->getJson('/auth/google/session',$h)->assertOk();
        $want=$state==='enrolled'||($state==='unenrolled'&&in_array($role,config('security.privileged_roles'),true));
        $r->assertJsonPath('session.mfa_required',$want)->assertJsonPath('role',$role);
        $u->refresh();$this->assertSame($secret,$u->mfa_secret);$this->assertSame($state==='opted_out',$u->mfa_disabled_at!==null);$this->assertNull($u->api_mfa_verified_at);
        $this->getJson('/auth/google/session',$h)->assertUnauthorized();
    }
    public static function formatCases():array {
        $cases=[];foreach(['super_admin','admin','bir','rdo']as$role)foreach([false,true]as$stale)$cases[$role.'-'.($stale?'stale':'fresh')]=[$role,$stale];return$cases;
    }
    #[DataProvider('formatCases')]
    public function test_nested_format_cannot_crash_or_bypass_privileged_mfa(string $role,bool $stale):void {
        [$u,$h]=$this->actor($role);if($stale)$u->forceFill(['api_authenticated_at'=>now()->subMinutes(25),'api_last_activity_at'=>now(),'api_mfa_verified_at'=>now()->subMinutes(20)])->save();
        $this->getJson('/api/tables/profiles?'.http_build_query(['eq'=>['id'=>(string)$u->id],'format'=>['csv']]),$h)->assertStatus(422);
        if($stale)$this->getJson('/api/tables/profiles?'.http_build_query(['eq'=>['id'=>(string)$u->id],'format'=>'csv']),$h)->assertForbidden()->assertJsonPath('reason','RECENT_MFA_REQUIRED');
        else $this->getJson('/api/tables/profiles?'.http_build_query(['eq'=>['id'=>(string)$u->id]]),$h)->assertStatus($role==='rdo'?403:200);
    }
}
