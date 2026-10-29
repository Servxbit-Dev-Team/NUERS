<?php

namespace App\Services;

/** Public merchant acknowledgement. Government processing is a separate internal workflow. */
class IntegrationStatus
{
    public function accepted(array $body): array
    {
        unset($body['bir_acceptance'], $body['bir_connection'], $body['bir_status'], $body['preparation']);

        return [...$body, 'nuers_status' => 'success', 'message' => 'Transaction successfully recorded by NUERS.', 'error' => null];
    }
}
