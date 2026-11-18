<?php

namespace App\Services\Infrastructure;

use Symfony\Component\Process\Process;

class AwsPowerController implements ControllerGateway
{
    public function ready(): bool
    {
        return config('infrastructure.enabled') && config('infrastructure.driver') === 'aws_power'
            && is_readable((string) config('infrastructure.aws_bridge'));
    }

    public function snapshot(): array
    {
        return app(ObservedInventory::class)->snapshot();
    }

    public function submit(array $job): array
    {
        return $this->request(['method' => 'submit', 'job' => $job]);
    }

    public function result(string $id): array
    {
        return $this->request(['method' => 'result', 'job_id' => $id]);
    }

    public function inspect(string $code, string $action): array
    {
        return $this->request(['method' => 'preflight', 'node_code' => $code, 'action' => $action]);
    }

    private function request(array $payload): array
    {
        if (! $this->ready()) {
            throw new \RuntimeException('AWS power controller is not configured.');
        }
        // Fixed operator-installed program, argv and bounded stdin; no shell interpolation.
        $process = new Process(['/usr/bin/python3', config('infrastructure.aws_bridge')]);
        $process->setInput(json_encode($payload, JSON_THROW_ON_ERROR));
        $process->setTimeout(22);
        $process->mustRun();
        $output = $process->getOutput();
        if (strlen($output) > 65536) {
            throw new \RuntimeException('Controller response exceeds limit.');
        }

        return json_decode($output, true, 32, JSON_THROW_ON_ERROR);
    }
}
