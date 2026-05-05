# Gitmeh API

This application provides a free API for [gitmeh](http://github.com/ryanhellyer/gitmeh), an automatic git commit tool I made.

This API uses the Gemma 3-4b model, which is extremely cost-effective, allowing me to provide this service for free to users of `gitmeh`. The application acts as a proxy, routing requests through its own API to protect the underlying AI provider's API key from being exposed or abused by end-users.

The JSON endpoint is **`POST /v1/chat/completions`**, intentionally the same path pattern as [OpenAI’s Chat Completions API](https://platform.openai.com/docs/api-reference/chat/create) (`/v1/` API version + `chat/completions` resource). That lets the gitmeh client (and anything else expecting an OpenAI-compatible base URL) call `https://<this-host>/v1` and append `/chat/completions` without a special case for this server.

## Authentication

Bearer auth is optional. Three modes:

| Mode | Behavior | API key forwarded downstream |
|---|---|---|
| No `Authorization` header | Uses the server's configured `OPENAI_API_KEY` | No |
| `Authorization: Bearer gitmeh-public-client` (or `GITMEH_HOSTED_TOKEN`) | Uses the server's configured `OPENAI_API_KEY` | No |
| `Authorization: Bearer <your-key>` (any non-hosted token) | Uses **your key** as the downstream API key | Yes |

## Model selection

The `model` field in the JSON body sets the primary model. When omitted, the provider's default model is used.

```json
"model": "gpt-4o-mini"
```

## Fallback models

The optional `fallback_models` field provides models to try if the primary fails (context-length exceeded, transient errors, rate limits). Each model is retried up to 3 times with exponential backoff before moving to the next fallback.

```json
"fallback_models": ["gpt-4o", "gpt-3.5-turbo"]
```

Full example with custom key and fallbacks:

```bash
curl -k -sS -X POST 'https://ai.hellyer.test/v1/chat/completions' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer sk-your-openai-key' \
  -d '{
    "model": "gpt-4o-mini",
    "fallback_models": ["gpt-4o", "gpt-3.5-turbo"],
    "messages": [
      {"role": "user", "content": "Unified diff:\n+a\n"}
    ]
  }' | jq .
```

## Provider selection

The optional `provider` field selects an upstream provider from `config/ai.php` (default from `GITMEH_PROVIDER`, falls back to `openrouter`). Available providers include `openai`, `anthropic`, `groq`, `mistral`, `deepseek`, `gemini`, `xai`, `ollama`, and others defined in that config file. Each provider uses its own configured API key and default model unless overridden via the `Authorization` header or `model` field.

## Legacy endpoint

The plain-text `POST /gitmeh` endpoint still works and always routes through the server's default provider configuration.

Uses PHP 8.5 and Laravel 13.

## Tests

```bash
./vendor/bin/pint --test
```

## Static analysis

```bash
./vendor/bin/phpstan analyse
```

## API test

Use this command to test the API.

```bash
curl -k -sS -X POST 'https://ai.hellyer.test/v1/chat/completions' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer gitmeh-public-client' \
  -d '{
    "model": "gitmeh-hosted",
    "fallback_models": ["google/gemma-3-4b-it"],
    "messages": [
      {"role": "user", "content": "Unified diff:\n+a\n"}
    ]
  }' | jq .
```

Smoke-test against a live host (defaults to production URL; override with `GITMEH_VERIFY_BASE` and `GITMEH_VERIFY_TOKEN` for staging):

```bash
./scripts/verify-hosted-api.sh
```

Legacy API request:
```bash
curl -skS --request POST \
  --header 'Content-Type: text/plain; charset=UTF-8' \
  --data-binary $'diff --git a/README.md b/README.md\n--- a/README.md\n+++
  b/README.md\n@@ -4,6 +4,11 @@\n ## Intro\n \n Short description.\n+\n+###
  GITMEH_PROBE_OK\n+\n+Document the /gitmeh smoke test: POST a unified diff
  as plain text.\n+\n ## License\n' \
  'https://ai.hellyer.kiwi/gitmeh'
```

## Unit tests

```bash
php artisan test tests/Unit
```

## Feature tests

```bash
php artisan test tests/Feature
```
