<?php

declare(strict_types=1);

return [

    'daily_limit' => (int) env('API_DAILY_LIMIT', 1000),

    'timezone' => env('API_RATE_LIMIT_TIMEZONE'),

    /*
    |--------------------------------------------------------------------------
    | Hosted OpenAI-compatible API (/v1/chat/completions)
    |--------------------------------------------------------------------------
    |
    | Optional bearer for official gitmeh clients. When Authorization is sent,
    | it must match this token (override with GITMEH_HOSTED_TOKEN for staging).
    |
    */

    'hosted_bearer_token' => env('GITMEH_HOSTED_TOKEN', 'gitmeh-public-client'),

    'max_json_request_bytes' => (int) env('GITMEH_MAX_JSON_BYTES', 2_097_152),

];
