<?php

return [
    'webhook_delivery_enabled' => false, // Enable only in a reviewed destination/egress deployment, never from a saved URL alone.
    'audit_checkpoint_key' => env('NUERS_AUDIT_CHECKPOINT_KEY'),
    'release_evidence_reference' => env('NUERS_RELEASE_EVIDENCE_REFERENCE'),
    'token_minutes' => max(60, (int) env('NUERS_SESSION_IDLE_MINUTES', 1440)),
    'session_absolute_minutes' => max(60, (int) env('NUERS_SESSION_MAX_MINUTES', 10080)),
    'privileged_step_up_minutes' => 15,
    'enforce_privileged_mfa' => true,
    'max_request_bytes' => 4 * 1024 * 1024,
    'privileged_roles' => ['admin', 'super_admin', 'bir', 'rdo'],
];
