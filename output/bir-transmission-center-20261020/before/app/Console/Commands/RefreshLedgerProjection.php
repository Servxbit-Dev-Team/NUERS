<?php

namespace App\Console\Commands;

use App\Services\LedgerProjection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RefreshLedgerProjection extends Command
{
    protected $signature = 'reports:refresh {--rebuild : Queue every source for a resumable rebuild} {--activate : Enable indexed reads after the backlog clears} {--max-seconds=45}';
    protected $description = 'Refresh the derivative reporting index without modifying financial source records';

    public function handle(LedgerProjection $projection): int
    {
        if ($this->option('rebuild')) $projection->enqueueAll();
        $deadline = microtime(true) + max(1, min(86400, (int) $this->option('max-seconds')));
        $processed = 0;
        do {
            $n = $projection->refresh();
            $processed += $n;
            if (!$n) break;
        } while (microtime(true) < $deadline);
        $remaining = DB::table('ledger_dirty_documents')->count();
        if ($this->option('activate') && !$remaining) $projection->activate();
        $this->line(json_encode(['processed' => $processed, 'remaining' => $remaining, 'ready' => $projection->ready()]));
        return 0;
    }
}
