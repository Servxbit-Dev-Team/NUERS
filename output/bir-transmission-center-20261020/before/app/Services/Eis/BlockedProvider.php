<?php

namespace App\Services\Eis;

use App\Contracts\EisProvider;

class BlockedProvider implements EisProvider
{
    public function ready(): bool
    {
        return false;
    }

    public function send(string $idempotencyKey, string $canonical): array
    {
        throw new \LogicException('Official BIR transport is not configured.');
    }

    public function inquire(string $idempotencyKey): array
    {
        throw new \LogicException('Official BIR inquiry is not configured.');
    }
}
