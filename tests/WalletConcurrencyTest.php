<?php
namespace Tests;

use App\Services\MerchantWallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\IsolatedSecurityDatabase;

/** Run explicitly through the isolated MariaDB harness; not a SQLite concurrency claim. */
class WalletConcurrencyTest extends TestCase
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

    private function race(string $action, string $merchant, array $events): array
    {
        $barrier = base_path('.local/vapt-mariadb-2026-11-14/barrier-'.Str::uuid());
        $jobs=[];
        try {
            foreach ($events as $event) {
                $pipes=[];
                $p=proc_open([PHP_BINARY, base_path('tests/Support/wallet-concurrency-worker.php'),$action,$merchant,$event,$barrier], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,base_path());
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

    public function test_concurrent_withdrawals_cannot_overdraw_or_break_ledger_chain(): void
    {
        $merchant=(string)Str::uuid();
        DB::table('merchants')->insert(['id'=>$merchant,'business_name'=>'Synthetic concurrent wallet','status'=>'active']);
        DB::transaction(fn()=>app(MerchantWallet::class)->credit($merchant,1000,'opening','Synthetic','test'));
        $results=$this->race('withdraw',$merchant,[(string)Str::uuid(),(string)Str::uuid()]);
        $statuses=array_column($results,'status');sort($statuses);
        $this->assertSame([200,409],$statuses);
        $this->assertSame(['valid'=>true,'entries'=>2,'balance_centavos'=>300],app(MerchantWallet::class)->audit($merchant));
    }

    public function test_concurrent_replay_credits_once_and_returns_the_same_entry(): void
    {
        $merchant=(string)Str::uuid();
        DB::table('merchants')->insert(['id'=>$merchant,'business_name'=>'Synthetic replay wallet','status'=>'active']);
        $results=$this->race('credit',$merchant,array_fill(0,4,'same-synthetic-funding-event'));
        $this->assertSame([200,200,200,200],array_column($results,'status'));
        $this->assertCount(1,array_unique(array_column($results,'entry_id')));
        $this->assertSame(['valid'=>true,'entries'=>1,'balance_centavos'=>1000],app(MerchantWallet::class)->audit($merchant));
    }
}
