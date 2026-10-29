<?php

namespace App\Console\Commands;

use App\Services\SalesExportQueue;
use Illuminate\Console\Command;

class BuildSalesExport extends Command
{
    protected $signature = 'reports:export';
    protected $description = 'Build private transaction PDFs and one bounded merchant sales CSV';
    public function handle(SalesExportQueue $queue): int
    {
        $deadline=microtime(true)+90;
        for($i=0;$i<3 && microtime(true)<$deadline;$i++) {
            $id=app(\App\Services\TransactionPdfQueue::class)->runOne();
            if(!$id)break;
            $this->info('Transaction PDF processed: '.$id);
        }
        $this->info($queue->runOne() ?? 'No queued sales exports.');
        return 0;
    }
}
