# Hosted gitmeh API: OpenAI-compatible chat (server-side work)

Use this document when modifying **your server** (the stack behind `https://ai.hellyer.kiwi/`). In Cursor or another AI tool, open your **server or infra repository** and attach this file (or paste its body) so the model can implement the contract. The **gitmeh** Go client in this repo already defaults to the request shape below.

## Filled parameters (sync with gitmeh client)

| Item | Value |
|------|--------|
| Public bearer token (weak client id, not a billing secret) | `gitmeh-public-client` |
| Default chat API base URL (no trailing slash) | `https://ai.hellyer.kiwi/v1` |
| Full completions URL | `https://ai.hellyer.kiwi/v1/chat/completions` |
| Legacy plain endpoint (keep during transition) | `POST https://ai.hellyer.kiwi/gitmeh` with `Content-Type: text/plain; charset=UTF-8`, body = raw unified diff, response = plain text commit message |
| Keep legacy plain path | **Yes** until old binaries are gone; same per-IP limits as today |
| Max JSON request body | **2097152** bytes (2 MiB) before parsing; reject larger with `413` or `400` and a short JSON error |

Optional: allow overriding the public token on the server via env; the client can override the bearer string with `GITMEH_HOSTED_TOKEN` for staging.

---

## Goal

Align the **default / built-in** gitmeh hosted service with the **same HTTP contract** the gitmeh Go client uses for external OpenAI-compatible providers: **`POST {baseURL}/chat/completions`** with JSON request/response.

Legacy behavior remains on `POST https://ai.hellyer.kiwi/gitmeh` as `text/plain` for users who set `GITMEH_LEGACY_PLAIN=true`.

## Reference client behavior (must match)

- **Method/path:** `POST {baseURL}/chat/completions` where `baseURL` has **no trailing slash** (client uses `base + "/chat/completions"`).
- **Headers (request):**
  - `Content-Type: application/json`
  - `Accept: application/json`
  - `Authorization: Bearer gitmeh-public-client` for official builds (or value from `GITMEH_HOSTED_TOKEN` when set). Treat the token as **optional** or **required** on the server, but document which; mismatches should return **401** with JSON error if you require it.
- **JSON request body (minimum fields to support):**
  - `model` (string) — client sends `gitmeh-hosted` by default for the hosted path; you may **ignore** and always run your local model, or **map** ids; return **400** if you require a specific model and it is missing.
  - `messages` (array) — client sends **two** messages: `role: "system"` (instructions) and `role: "user"` with content `Unified diff:\n` + unified diff text.
  - `temperature` (number, e.g. 0.3) — optional to honor; safe to clamp.
  - `max_tokens` (number, e.g. 512) — optional to honor; cap server-side for cost control.
- **JSON response body (success):** OpenAI shape, at minimum:
  - `choices` non-empty array
  - `choices[0].message.content` string = assistant commit message (plain text; client trims whitespace).
- **Errors (non-2xx):** Prefer `{"error":{"message":"...","type":"...","code":"..."}}` so clients can surface `error.message`.

## Auth policy (implemented on server)

Use **optional Bearer** for `https://ai.hellyer.kiwi/v1/chat/completions`:

- If `Authorization: Bearer gitmeh-public-client` matches, treat as official gitmeh client (same rate limits as legacy).
- If header missing or wrong: either same limits (public) or **401** — pick one and document; client always sends the bearer for the hosted default.

## Server implementation checklist

1. **Routing:** `POST` on `/v1/chat/completions` (under your TLS host); **405** for wrong methods.
2. **Parse JSON** with **2 MiB** max body; reject oversized bodies before model call.
3. **Extract diff** from `messages`: prefer the last `user` message; strip an optional `Unified diff:\n` prefix for robustness.
4. **Build prompt** for your local model: system text from `role == "system"` messages; user content = diff.
5. **Reuse** the same inference path as the legacy `text/plain` endpoint so behavior stays consistent.
6. **Response:** `Content-Type: application/json`; **200** with `choices[0].message.content` set to the commit message only (no markdown fences).
7. **Rate limiting:** Same per-IP limits as legacy `/gitmeh`.
8. **Timeouts:** Compatible with ~20s client HTTP timeout.
9. **Logging:** Status, latency; avoid logging full diffs if privacy matters.
10. **Tests:** Happy path JSON; missing `messages`; empty `choices`; malformed JSON; oversize body; rate limit if testable.

## Deployment notes

- Reverse proxy: allow at least **2 MiB** upload for this route.
- Preserve real client IP for rate limiting (`X-Forwarded-For` trust).

## Flow (optional)

```mermaid
flowchart LR
  subgraph client [gitmeh_client]
    ChatPOST[POST_chat_completions_JSON]
  end
  subgraph server [hellyer_hosted_API]
    Legacy[legacy_text_plain_gitmeh]
    NewChat[v1_chat_completions]
    Model[shared_model_inference]
  end
  ChatPOST --> NewChat --> Model
  Legacy --> Model
```

## After the API is live

Run `./scripts/verify-hosted-api.sh` from this repo (or set `GITMEH_VERIFY_BASE` / `GITMEH_VERIFY_TOKEN` for staging). Expect HTTP **200** and non-empty `choices[0].message.content`.
