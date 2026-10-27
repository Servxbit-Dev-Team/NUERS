<?php
namespace App\Console\Commands;
use App\Services\MerchantWebhooks;
use Illuminate\Console\Command;
class DeliverMerchantWebhooks extends Command
{
    protected $signature='merchant-webhooks:work {--limit=100} {--max-seconds=45} {--watch} {--idle-sleep=1} {--max-runtime=3600}';
    protected $description='Deliver only explicitly verified merchant callbacks with bounded processing and durable leases. With --watch it runs until --max-runtime seconds, for supervised parallel workers.';
    public function handle():int
    {
        $service=app(MerchantWebhooks::class);
        if($this->option('watch'))return $this->watch($service);
        $stop=microtime(true)+max(1,min(50,(int)$this->option('max-seconds')));$count=0;
        for($i=0;$i<max(1,min(500,(int)$this->option('limit')))&&microtime(true)<$stop;$i++){
            $state=$service->runOne();if(!$state||$state==='disabled')break;$count++;
        }
        $this->line(json_encode(['processed'=>$count,'enabled'=>$service->enabled()]));return self::SUCCESS;
    }
    /**
     * Long-running worker. Run several copies under a process manager (supervisor/systemd); each leases its own deliveries.
     * It exits after --max-runtime seconds so memory is recycled, and the manager starts a fresh one. A worker that is killed
     * mid-delivery leaves a lease that expires on its own, after which the delivery is retried under the existing retry rules.
     */
    private function watch(MerchantWebhooks $service):int
    {
        $stop=microtime(true)+max(1,min(86400,(int)$this->option('max-runtime')));$idle=max(1,min(60,(int)$this->option('idle-sleep')));$count=0;
        // Never sleep past the end of the run, so the process stops promptly when --max-runtime is reached.
        $pause=fn(float $seconds)=>usleep((int)(max(0,min($seconds,$stop-microtime(true)))*1000000));
        while(microtime(true)<$stop){
            $state=$service->runOne();
            if($state==='disabled'){$pause(min(10,$idle*5));continue;}
            if(!$state){$pause($idle);continue;}
            $count++;
        }
        $this->line(json_encode(['processed'=>$count,'enabled'=>$service->enabled(),'mode'=>'watch']));return self::SUCCESS;
    }
}
