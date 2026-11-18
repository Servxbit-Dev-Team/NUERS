<?php

return [
    // Operator-only settings. Never accept these values through an HTTP form.
    'enabled' => (bool) env('NUERS_INFRA_CONTROLLER_ENABLED', false),
    'driver' => env('NUERS_INFRA_DRIVER', 'mtls'),
    'aws_bridge' => env('NUERS_INFRA_AWS_BRIDGE'),
    'backup_enabled' => (bool) env('NUERS_INFRA_BACKUP_ENABLED', false),
    'backup_bridge' => env('NUERS_INFRA_BACKUP_BRIDGE'),
    'backup_nodes' => array_values(array_filter(explode(',', (string) env('NUERS_INFRA_BACKUP_NODES', '')))),
    'aws_power_nodes' => array_values(array_filter(explode(',', (string) env('NUERS_INFRA_AWS_POWER_NODES', '')))),
    'execution_enabled' => (bool) env('NUERS_INFRA_EXECUTION_ENABLED', false),
    'controller_url' => env('NUERS_INFRA_CONTROLLER_URL'),
    'controller_host' => env('NUERS_INFRA_CONTROLLER_HOST'),
    'ca_file' => env('NUERS_INFRA_CA_FILE'),
    'client_cert' => env('NUERS_INFRA_CLIENT_CERT'),
    'client_key' => env('NUERS_INFRA_CLIENT_KEY'),
    'signing_key_file' => env('NUERS_INFRA_SIGNING_KEY_FILE'),
    'freshness_seconds' => 120,
    'observation_file' => env('NUERS_INFRA_OBSERVATION_FILE'),
    'observation_public_key' => env('NUERS_INFRA_OBSERVATION_PUBLIC_KEY'),
    'step_up_seconds' => 300,
    'sample_limit' => 672, // At most 7 days of 15-minute samples per node.
    'budget_ceiling_php' => (int) env('NUERS_INFRA_BUDGET_CEILING_PHP', 100000),
    // Reviewed pins are installed out of band; a UI enrollment request is never trusted.
    'node_pins' => is_readable((string) env('NUERS_INFRA_PINS_FILE')) ? json_decode(file_get_contents(env('NUERS_INFRA_PINS_FILE')), true, 16, JSON_THROW_ON_ERROR) : [], // ['WEB-01' => ['fingerprint' => '<64 hex>', 'environment' => 'staging', 'tier' => 'web', 'ha_group' => 'WEB-HA']]
    'capabilities' => array_values(array_filter(explode(',', (string) env('NUERS_INFRA_CAPABILITIES', '')))), // Explicit reviewed action allowlist, additionally checked against telemetry.
];
