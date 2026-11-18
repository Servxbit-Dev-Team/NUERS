<?php

namespace App\Console\Commands;

use App\Services\MerchantWebhooks;
use Illuminate\Console\Command;

class DeliverMerchantWebhooks extends Command
{
    protected $signature = 'merchant-webhooks:work {--limit=100} {--max-seconds=45}';
    protected $description = 'Deliver only explicitly verified merchant callbacks with bounded processing and durable leases.';
    public function handle(): int
    {
        $service = app(MerchantWebhooks::class);
        $stop = microtime(true) + max(1, min(50, (int)$this->option('max-seconds')));
        $count = 0;
        for ($i = 0;$i < max(1, min(500, (int)$this->option('limit'))) && microtime(true) < $stop;$i++) {
            $state = $service->runOne();
            if (!$state || $state === 'disabled') {
                break;
            }$count++;
        }
        $this->line(json_encode(['processed' => $count,'enabled' => $service->enabled()]));
        return self::SUCCESS;
    }
}
