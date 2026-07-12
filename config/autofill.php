<?php

return [
    // Phone used for auto-fill submissions. Can be overridden in .env or via command option
    'phone' => env('AUTO_FILLER_PHONE', '09028559891'),

    // Name used for auto-fill submissions. Can be overridden in .env or via command option
    'name' => env('AUTO_FILLER_NAME', 'Test User'),

    // Microseconds to sleep between requests (useful to avoid hammering remote servers)
    'sleep_us' => env('AUTO_FILLER_SLEEP_US', 1000), // 0.1s

    // Whether AutoFormFiller should run in debug mode
    'debug' => env('AUTO_FILLER_DEBUG', false),

    // Schedule expression for the task. If empty, Kernel default will be used.
    'schedule' => env('AUTO_FILLER_SCHEDULE', 'daily'),


    // Legacy key used by older code paths.
    'sms_timeout' => env('AUTO_FILLER_SMS_TIMEOUT', 30),

    // Total allowed runtime (seconds) for the queue job.
    'sms_job_timeout' => env('AUTO_FILLER_SMS_JOB_TIMEOUT', 18000),

    // Per-request HTTP timeouts used by autofill and SMS API calls.
    'http_timeout' => env('AUTO_FILLER_HTTP_TIMEOUT', 30),
    'http_connect_timeout' => env('AUTO_FILLER_HTTP_CONNECT_TIMEOUT', 10),
];
