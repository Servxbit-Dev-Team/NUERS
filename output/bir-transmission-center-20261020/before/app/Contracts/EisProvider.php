<?php

namespace App\Contracts;

/** Official contract must supply a verified implementation. No guessed HTTP URL or response fields. */
interface EisProvider
{
    public function ready(): bool;

    /** outcome: accepted|rejected|retryable|uncertain|not_found; authenticated evidence required for terminal outcomes. */
    public function send(string $idempotencyKey, string $canonical): array;

    public function inquire(string $idempotencyKey): array;
}
