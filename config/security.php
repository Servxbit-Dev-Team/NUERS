<?php

return [
    'upload_scan' => [
        // Deployed environments cannot opt out through a feature flag.
        'enabled' => ! in_array(env('APP_ENV', 'production'), ['local', 'testing'], true),
        'socket' => '/run/clamav/clamd.ctl',
    ],
    'api_network_per_minute' => 1200,
    'api_public_per_minute' => 300,
    'api_account_per_minute' => 600,
    'integration_key_per_minute' => 600,
    'merchant_webhook_delivery_enabled' => (bool) env('NUERS_MERCHANT_WEBHOOK_DELIVERY_ENABLED', true),
    'webhook_delivery_enabled' => false, // Enable only in a reviewed destination/egress deployment, never from a saved URL alone.
    'audit_checkpoint_key' => env('NUERS_AUDIT_CHECKPOINT_KEY'),
    'release_evidence_reference' => env('NUERS_RELEASE_EVIDENCE_REFERENCE'),
    'token_minutes' => max(60, (int) env('NUERS_SESSION_IDLE_MINUTES', 1440)),
    'session_absolute_minutes' => max(60, (int) env('NUERS_SESSION_MAX_MINUTES', 10080)),
    // Privileged access has hard upper bounds, even if merchant sessions are long-lived.
    'privileged_idle_minutes' => min(30, max(5, (int) env('NUERS_PRIVILEGED_IDLE_MINUTES', 30))),
    'privileged_absolute_minutes' => min(480, max(30, (int) env('NUERS_PRIVILEGED_MAX_MINUTES', 480))),
    'privileged_mfa_minutes' => min(5, max(1, (int) env('NUERS_PRIVILEGED_MFA_MINUTES', 5))),
    'mfa_challenge' => 'login_session',
    'enforce_privileged_mfa' => true,
    'max_request_bytes' => 4 * 1024 * 1024,
    'privileged_roles' => ['admin', 'super_admin', 'bir', 'rdo'],
];
