<?php

return [
    'contract' => 'EIS API guide 2.3 / JSON 2.1, downloaded 2026-10-28',
    'guide_url' => 'https://eis-cert.bir.gov.ph/#/apiGuide',
    // Endpoint choices are pinned; secrets can never be sent to arbitrary configured URLs.
    'origins' => ['certification' => 'https://eis-cert.bir.gov.ph', 'production' => 'https://eis-api.bir.gov.ph'],
    'prefixes' => ['certification' => '/api', 'production' => '/v2'],
    'timeout_seconds' => 25,
    'max_response_bytes' => 2097152,
    'batch_limit' => 100,
    // A deployment switch is independent of the per-company release and authority gates.
    'outbound_enabled' => env('BIR_EIS_OUTBOUND_ENABLED', false),
    'callback_enabled' => env('BIR_EIS_CALLBACK_ENABLED', false),
];
