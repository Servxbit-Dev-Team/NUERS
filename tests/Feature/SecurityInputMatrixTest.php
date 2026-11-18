<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\IsolatedSecurityDatabase;
use Tests\TestCase;

class SecurityInputMatrixTest extends TestCase
{
    use IsolatedSecurityDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','cache.default'=>'array','queue.default'=>'database','app.debug'=>false,'security.enforce_privileged_mfa'=>true,'notifications.dispatch_enabled'=>false]);
        $this->securityDatabase();
        $this->securityMigrate();
        Http::preventStrayRequests();
        Mail::fake();
    }

    private function actor(string $role): array
    {
        $token=Str::random(64);
        $u=User::create(['name'=>'Synthetic input audit','email'=>Str::uuid().'@example.test','password'=>'Synthetic-Only-Password7','api_token'=>$token]);
        DB::table('profiles')->insert(['id'=>$u->id,'email'=>$u->email,'role'=>$role]);
        return [$u,['Authorization'=>'Bearer '.$token]];
    }

    public function test_query_and_cookie_credentials_cannot_replace_authorization_header(): void
    {
        [$u,$h]=$this->actor('client');
        $token=substr($h['Authorization'],7);
        $this->getJson('/api/tables/profiles?'.http_build_query(['access_token'=>$token,'api_token'=>$token,'role'=>'super_admin']))->assertUnauthorized();
        $this->withCookie('nuers_api_token',$token)->getJson('/api/tables/profiles')->assertUnauthorized();
        $this->postJson('/api/tables/profiles',['access_token'=>$token,'values'=>['role'=>'super_admin']])->assertUnauthorized();
        $this->assertDatabaseHas('profiles',['id'=>$u->id,'role'=>'client']);
    }

    public function test_sql_metacharacters_in_filter_values_cannot_widen_the_account_scope(): void
    {
        [$u,$h]=$this->actor('client');
        $this->actor('client');
        foreach (["' OR '1'='1",'1) OR 1=1--','${7*7}','{{7*7}}','../../etc/passwd'] as $value) {
            $this->getJson('/api/tables/profiles?'.http_build_query(['eq'=>json_encode(['id'=>$value])]),$h)->assertOk()->assertJsonPath('data',[]);
        }
        $this->getJson('/api/tables/profiles',$h)->assertOk()->assertJsonCount(1,'data');
        $this->assertDatabaseCount('users',2);
    }

    public function test_nested_filter_values_are_rejected_without_server_errors(): void
    {
        [$u,$h]=$this->actor('client');
        foreach ([['eq'=>json_encode(['id'=>['nested']])],['eq'=>['id'=>['nested']]],['in'=>json_encode(['id'=>[['nested']]])]] as $query) {
            $this->getJson('/api/tables/profiles?'.http_build_query($query),$h)->assertStatus(422);
        }
    }

    public function test_array_profile_query_does_not_crash_or_bypass_privileged_mfa(): void
    {
        [$u,$h]=$this->actor('super_admin');
        $this->getJson('/api/tables/profiles?'.http_build_query(['eq'=>['id'=>(string)$u->id]]),$h)->assertOk()->assertJsonCount(1,'data');
        foreach ([['id'=>['nested']],['id'=>'foreign']] as $eq) {
            $r=$this->getJson('/api/tables/profiles?'.http_build_query(['eq'=>$eq]),$h);
            $this->assertContains($r->status(),[403,422]);
        }
        $this->getJson('/api/tables/merchants',$h)->assertForbidden()->assertJsonPath('code','MFA_REQUIRED');
    }

    public function test_untrusted_origins_cannot_read_credentialed_api_responses(): void
    {
        foreach (['https://attacker.invalid','null'] as $origin) {
            $r=$this->options('/api/tables/profiles',[],['Origin'=>$origin,'Access-Control-Request-Method'=>'POST','Access-Control-Request-Headers'=>'authorization,content-type']);
            $this->assertNotContains($r->headers->get('Access-Control-Allow-Origin'),['*',$origin]);
        }
    }
}
