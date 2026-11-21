<?php

return [
    'require_https' => true,
    'requests_per_minute' => 60,
    'unauthenticated_requests_per_minute' => 120,
    'maximum_token_days' => 90,
    'status_stale_after_seconds' => 300,
    'status_retention_seconds' => 86400,
];
