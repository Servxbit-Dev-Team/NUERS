<?php
namespace Tests;

use App\Services\MerchantWallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\IsolatedSecurityDatabase;

/** Run explicitly through the isolated MariaDB harness; not a SQLite concurrency claim. */
class RegistrationConcurrencyTest extends TestCase
{
    use IsolatedSecurityDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('DB_PORT') !== '3311') {
            throw new \RuntimeException('Requires isolated MariaDB concurrency harness.');
        }
        $this->securityDatabase();
        $this->securityMigrate();
    }

    private function race(string $challenge, array $events): array
    {
        $barrier = base_path('.local/vapt-mariadb-2026-11-14/barrier-'.Str::uuid());
        $jobs=[];
        try {
            foreach ($events as $event) {
                $pipes=[];
                $p=proc_open([PHP_BINARY, base_path('tests/Support/registration-concurrency-worker.php'),$challenge,$event,$barrier], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,base_path());
                $this->assertIsResource($p);
                fclose($pipes[0]);
                $jobs[]=[$p,$pipes];
            }
            touch($barrier);
            $results=[];
            foreach ($jobs as [$p,$pipes]) {
                $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);
                fclose($pipes[1]);fclose($pipes[2]);
                $this->assertSame(0,proc_close($p),$error);
                $results[]=json_decode($output,true,512,JSON_THROW_ON_ERROR);
            }
            return $results;
        } finally {
            @unlink($barrier);
        }
    }

    private function challenge(): string
    {
        $id=(string) Str::uuid();
        DB::table('registration_email_verifications')->insert(['id'=>$id, 'email'=>'synthetic@example.test', 'code_hash'=>\Illuminate\Support\Facades\Hash::make('123456'), 'attempts'=>0, 'expires_at'=>now()->addMinutes(10), 'development_delivery'=>false, 'created_at'=>now(), 'updated_at'=>now()]);
        return $id;
    }

    public function test_concurrent_correct_otp_can_create_only_one_verification_proof(): void
    {
        $id=$this->challenge();
        $results=$this->race($id,array_fill(0,4,'123456'));
        $statuses=array_column($results,'status');sort($statuses);
        $this->assertSame([200,422,422,422],$statuses);
        $winner=array_values(array_filter($results,fn($r)=>$r['status']===200))[0];
        $row=DB::table('registration_email_verifications')->where('id',$id)->first();
        $this->assertSame($winner['proof_hash'],$row->proof_hash);
        $this->assertSame(1,(int)$row->attempts);
        $this->assertNotNull($row->verified_at);
        foreach($results as $result) if($result['status']===422) $this->assertSame('REGISTRATION_CODE_USED',$result['code']);
    }

    public function test_concurrent_wrong_otps_cannot_exceed_attempt_budget(): void
    {
        $id=$this->challenge();
        $results=$this->race($id,array_fill(0,7,'000000'));
        $this->assertSame(array_fill(0,7,422),array_column($results,'status'));
        $codes=array_count_values(array_column($results,'code'));
        $this->assertSame(4,$codes['REGISTRATION_CODE_INCORRECT'] ?? 0);
        $this->assertSame(3,$codes['REGISTRATION_CODE_LOCKED'] ?? 0);
        $row=DB::table('registration_email_verifications')->where('id',$id)->first();
        $this->assertSame(5,(int)$row->attempts);
        $this->assertNull($row->proof_hash);
        $this->assertNull($row->verified_at);
        $this->postJson('/api/registration/verify-code',['challenge_id'=>$id,'code'=>'123456'])->assertStatus(422)->assertJsonPath('code','REGISTRATION_CODE_LOCKED');
    }
}
