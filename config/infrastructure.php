<?php

return [
    // Operator-only settings. Never accept these values through an HTTP form.
    'enabled' => (bool) env('NUERS_INFRA_CONTROLLER_ENABLED', false),
    'execution_enabled' => (bool) env('NUERS_INFRA_EXECUTION_ENABLED', false),
    'controller_url' => env('NUERS_INFRA_CONTROLLER_URL'),
    'controller_host' => env('NUERS_INFRA_CONTROLLER_HOST'),
    'ca_file' => env('NUERS_INFRA_CA_FILE'),
    'client_cert' => env('NUERS_INFRA_CLIENT_CERT'),
    'client_key' => env('NUERS_INFRA_CLIENT_KEY'),
    'signing_key_file' => env('NUERS_INFRA_SIGNING_KEY_FILE'),
    'freshness_seconds' => 120,
    'step_up_seconds' => 300,
    'sample_limit' => 672, // At most 7 days of 15-minute samples per node.
    // Reviewed pins are installed out of band; a UI enrollment request is never trusted.
    'node_pins' => [], // ['WEB-01' => ['fingerprint' => '<64 hex>', 'environment' => 'staging', 'tier' => 'web', 'ha_group' => 'WEB-HA']]
    'capabilities' => [], // Explicit reviewed action allowlist, additionally checked against telemetry.
];
