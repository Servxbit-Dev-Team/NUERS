<?php

namespace App\Services\Infrastructure;

interface ControllerGateway
{
    public function ready(): bool;

    public function snapshot(): array;

    public function submit(array $job): array;

    public function result(string $id): array;
}
