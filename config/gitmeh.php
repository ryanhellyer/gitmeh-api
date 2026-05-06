<?php

declare(strict_types=1);

return [

    'daily_limit' => (int) env('API_DAILY_LIMIT', 1000),

    'timezone' => env('API_RATE_LIMIT_TIMEZONE'),

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider
    |--------------------------------------------------------------------------
    |
    | The provider used when no "provider" field is sent in the request.
    | Must match a key under config('ai.providers'), e.g. "openrouter",
    | "openai", "anthropic", "groq", "mistral", etc.
    |
    */

    'default_provider' => env('GITMEH_PROVIDER', 'openrouter'),

    /*
    |--------------------------------------------------------------------------
    | Default System Prompt
    |--------------------------------------------------------------------------
    |
    | Sent as the system message when the request does not include one.
    | Override with GITMEH_PROMPT.
    |
    */

    'prompt' => env('GITMEH_PROMPT', 'Write a Git commit message (Conventional Commits format) for this diff. Reply with ONLY the commit message. No analysis, no explanation, no preamble. Start with a verb. No numbering. No bullet points.'),

    /*
    |--------------------------------------------------------------------------
    | Inference Timeout (seconds)
    |--------------------------------------------------------------------------
    |
    | Caps the upstream AI call so responses usually finish within the
    | gitmeh client's HTTP timeout.
    |
    */

    'timeout' => (int) env('GITMEH_CHAT_TIMEOUT', 120),

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
    */

    'hosted_bearer_token' => env('GITMEH_HOSTED_TOKEN', 'gitmeh-public-client'),

    'max_json_request_bytes' => (int) env('GITMEH_MAX_JSON_BYTES', 2_097_152),

    'chat_inference_timeout_seconds' => (int) env('GITMEH_CHAT_INFERENCE_TIMEOUT', 120),

];