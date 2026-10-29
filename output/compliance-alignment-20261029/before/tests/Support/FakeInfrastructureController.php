<?php

namespace Tests\Support;

use App\Services\Infrastructure\ControllerGateway;

/** Only registered by isolated tests. Never selectable through config or HTTP. */
class FakeInfrastructureController implements ControllerGateway
{
    public bool $available = true;

    public bool $timeout = false;

    public array $jobs = [];

    public array $observation = [];

    public array $outcome = ['status' => 'succeeded', 'postcheck_passed' => true, 'code' => 'COMPLETED'];

    public function ready(): bool
    {
        return $this->available;
    }

    public function snapshot(): array
    {
        return $this->observation;
    }

    public function submit(array $job): array
    {
        $this->jobs[$job['job_id']] = $job;
        if ($this->timeout) {
            throw new \RuntimeException('synthetic ambiguous timeout, token=DO_NOT_PERSIST');
        }

        return ['job_id' => $job['job_id']] + $this->outcome;
    }

    public function result(string $id): array
    {
        return ['job_id' => $id] + $this->outcome;
    }
}
