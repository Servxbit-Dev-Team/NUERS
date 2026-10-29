<?php

return [
    // Keep external notification delivery paused until the rollout is authorized.
    // SMTP tests remain explicit administrator actions.
    'dispatch_enabled' => (bool) env('NUERS_NOTIFICATION_DISPATCH_ENABLED', false),
];
