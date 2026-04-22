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
    | Route path follows OpenAI's REST layout (POST /v1/chat/completions) so the
    | gitmeh client can use the same base URL as for other OpenAI-compatible hosts.
    |
    | Auth: Bearer is optional. Requests without Authorization are allowed (same
    | daily rate limit). If Authorization is present, the Bearer token must match
    | hosted_bearer_token or the server returns 401 JSON (GITMEH_HOSTED_TOKEN).
    |
    | chat_inference_timeout_seconds caps the OpenRouter call for this endpoint so
    | responses usually finish within the gitmeh client's ~20s HTTP timeout; legacy
    | POST /gitmeh still uses OPENROUTER_TIMEOUT from services.openrouter.
    |
    */

    'hosted_bearer_token' => env('GITMEH_HOSTED_TOKEN', 'gitmeh-public-client'),

    'max_json_request_bytes' => (int) env('GITMEH_MAX_JSON_BYTES', 2_097_152),

    'chat_inference_timeout_seconds' => (int) env('GITMEH_CHAT_INFERENCE_TIMEOUT', 20),

];
