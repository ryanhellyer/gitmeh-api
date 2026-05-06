#!/usr/bin/env bash
set -euo pipefail

# Verify the hosted OpenAI-compatible chat completions endpoint.
# Override for staging: GITMEH_VERIFY_BASE, GITMEH_VERIFY_TOKEN

BASE="${GITMEH_VERIFY_BASE:-https://ai.hellyer.kiwi/v1}"
TOKEN="${GITMEH_VERIFY_TOKEN:-gitmeh-public-client}"

payload='{"model":"gitmeh-hosted","messages":[{"role":"system","content":"Write a short git commit message. Imperative mood. Message only."},{"role":"user","content":"Unified diff:\n--- a/foo\n+++ b/foo\n@@ -0,0 +1 @@\n+bar\n"}],"temperature":0.3,"max_tokens":512}'

resp="$(curl -sS -w "\n%{http_code}" -X POST "${BASE}/chat/completions" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d "${payload}")"

code="$(echo "${resp}" | tail -n1)"
body="$(echo "${resp}" | sed '$d')"

if [[ "${code}" != "200" ]]; then
  echo "Expected HTTP 200, got ${code}" >&2
  echo "${body}" >&2
  exit 1
fi

if command -v jq >/dev/null 2>&1; then
  content="$(echo "${body}" | jq -r '.choices[0].message.content // empty')"
else
  content="$(python3 -c "import json,sys; d=json.loads(sys.stdin.read()); print((d.get('choices') or [{}])[0].get('message',{}).get('content') or '')" <<< "${body}")"
fi

if [[ -z "${content// /}" ]]; then
  echo "Empty choices[0].message.content" >&2
  echo "${body}" >&2
  exit 1
fi

echo "${content}"
