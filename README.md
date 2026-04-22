# Gitmeh API

This application provides a free API for [gitmeh](http://github.com/ryanhellyer/gitmeh), an automatic git commit tool I made.

This API uses the Gemma 3-4b model, which is extremely cost-effective, allowing me to provide this service for free to users of `gitmeh`. The application acts as a proxy, routing requests through its own API to protect the underlying AI provider's API key from being exposed or abused by end-users.

The JSON endpoint is **`POST /v1/chat/completions`**, intentionally the same path pattern as [OpenAI’s Chat Completions API](https://platform.openai.com/docs/api-reference/chat/create) (`/v1/` API version + `chat/completions` resource). That lets the gitmeh client (and anything else expecting an OpenAI-compatible base URL) call `https://<this-host>/v1` and append `/chat/completions` without a special case for this server.

Bearer auth for that route is optional: omit `Authorization` or send `Authorization: Bearer gitmeh-public-client` (or the value of `GITMEH_HOSTED_TOKEN` if you override it on the server). If a Bearer header is sent and the token does not match, the API returns **401** JSON.

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
curl -k -sS -X POST 'https://ai.hellyer.test/v1/chat/completions' -H 'Content-Type: application/json' -H 'Authorization: Bearer gitmeh-public-client' -d '{"model":"gitmeh-hosted","messages":[{"role":"user","content":"Unified diff:\n+a\n"}]}' | jq .
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
