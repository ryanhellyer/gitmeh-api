#!/usr/bin/env bash
set -euo pipefail

BASE="${GITMEH_VERIFY_BASE:-https://ai.hellyer.test/v1}"
TOKEN="${GITMEH_VERIFY_TOKEN:-gitmeh-public-client}"

curl -k -X POST "${BASE}/chat/completions" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"model":"gitmeh-hosted","messages":[{"role":"system","content":"Write a short git commit message. Imperative mood. Message only."},{"role":"user","content":"Unified diff:\n--- a/foo\n+++ b/foo\n@@ -0,0 +1 @@\n+bar\n"}],"temperature":0.3,"max_tokens":512}'
