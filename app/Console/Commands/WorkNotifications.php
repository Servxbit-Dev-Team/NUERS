<?php

namespace App\Console\Commands;

use App\Services\PlatformNotifications;
use Illuminate\Console\Command;

class WorkNotifications extends Command
{
    protected $signature = 'notifications:work
        {--limit=25 : Maximum messages per batch}
        {--watch : Continuously consume the existing outbox}
        {--sleep=1 : Idle polling interval in seconds}
        {--max-time=3600 : Recycle the continuous worker after this many seconds}';

    protected $description = 'Deliver encrypted transactional mail without replaying ambiguous SMTP outcomes';

    public function handle(PlatformNotifications $notifications): int
    {
        if (! config('notifications.dispatch_enabled')) {
            $this->warn('External notification dispatch is paused.');

            return self::FAILURE;
        }

        $limit = max(1, min(100, (int) $this->option('limit')));
        $sleep = max(1, min(30, (int) $this->option('sleep')));
        $watch = (bool) $this->option('watch');
        $deadline = hrtime(true) + max(1, min(86400, (int) $this->option('max-time'))) * 1_000_000_000;
        $stop = false;

        if (extension_loaded('pcntl')) {
            // Finish the claimed message before stopping, including an SMTP exchange.
            $this->trap([SIGTERM, SIGINT], function () use (&$stop): void {
                $stop = true;
            });
        }

        try {
            do {
                $processed = 0;
                while (! $stop && $processed < $limit) {
                    if ($watch && hrtime(true) >= $deadline) {
                        break 2;
                    }
                    if (! $notifications->runOne()) {
                        break;
                    }
                    $processed++;
                }

                if (! $watch || $stop || hrtime(true) >= $deadline) {
                    break;
                }
                // Drain a busy queue immediately. Only idle workers pause.
                if ($processed < $limit) {
                    usleep((int) min($sleep * 1_000_000, max(0, ($deadline - hrtime(true)) / 1000)));
                }
            } while (! $stop);
        } finally {
            $this->untrap();
        }

        return self::SUCCESS;
    }
}
