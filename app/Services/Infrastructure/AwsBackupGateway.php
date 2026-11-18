<?php

namespace App\Services\Infrastructure;

use Symfony\Component\Process\Process;

class AwsBackupGateway
{
    public function ready(): bool
    {
        return config('infrastructure.backup_enabled') && is_readable((string) config('infrastructure.backup_bridge'));
    }

    public function start(array $request): array
    {
        $process = new Process(['/usr/bin/python3', config('infrastructure.backup_bridge')]);
        $process->setInput(json_encode($request, JSON_THROW_ON_ERROR));
        $process->setTimeout(22);
        $process->mustRun();
        abort_if(strlen($process->getOutput()) > 16384, 502, 'Backup response exceeded the allowed size.');
        $result = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
        if (! is_string($result['job_id'] ?? null) || ! preg_match('/^[a-zA-Z0-9-]{10,100}$/', $result['job_id'])) {
            throw new \RuntimeException('Invalid backup job response.');
        }

        return $result;
    }
}
