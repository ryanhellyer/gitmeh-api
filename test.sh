#!/usr/bin/env bash
#
# Smoke tests for the translations endpoint: POST /v1/translations
#
# Usage:
#   ./test.sh                                  # against http://127.0.0.1:8000
#   ./test.sh --base-url https://ai.hellyer.kiwi
#   ./test.sh --offline                        # validation only, no model calls
#   ./test.sh --verify                         # enforce TLS verification (curl -k is on by default)
#
# Local server, if you need one:
#   APP_ENV=dev php -S 127.0.0.1:8000 -t public
#
# Notes:
#   * Every POST counts against the shared per-IP hourly quota (API_HOURLY_LIMIT),
#     including the 4xx cases, because the rate limiter runs before the controller.
#   * Happy-path tests make real model calls. Use --offline to skip them and test
#     only validation/routing (no OpenRouter spend).
#
# Env overrides: BASE_URL, FROM_LANG, TO_LANG, SAMPLE_TEXT, MAX_TIME
#
# Requires: curl, and jq or python3.

set -uo pipefail

BASE_URL="https://ai.hellyer.test"
FROM_LANG="${FROM_LANG:-English}"
TO_LANG="${TO_LANG:-German}"
SAMPLE_TEXT="${SAMPLE_TEXT:-Please send me the report when you have finished it.}"
MAX_TIME="${MAX_TIME:-130}"
LIVE=1
# Local dev hosts usually present a self-signed cert (CN=workspace) that curl
# refuses, so verification is disabled by default. Pass --verify to enforce it.
INSECURE=1

usage() {
    cat <<'USAGE'
Smoke tests for the translations endpoint: POST /v1/translations

Usage:
  ./test.sh                                  # against http://127.0.0.1:8000
  ./test.sh --base-url https://ai.hellyer.test
  ./test.sh --verify                         # enforce TLS verification (default: bypassed)
  ./test.sh --offline                        # validation only, no model calls

Local server, if you need one:
  APP_ENV=dev php -S 127.0.0.1:8000 -t public

Notes:
  * Every POST counts against the shared per-IP hourly quota (API_HOURLY_LIMIT),
    including the 4xx cases, because the rate limiter runs before the controller.
  * Happy-path tests make real model calls. Use --offline to skip them and test
    only validation/routing (no OpenRouter spend).
  * Defaults are English -> German ("Please send me the report when you have
    finished it."), so the formal/informal tones show the Sie/du difference.
  * Every request prints the from/to/tone it sends (a "·" line), and successful
    translations print the echoed from/to plus a preview of the result.
  * curl runs with -k by default because local dev hosts often present a
    self-signed cert (e.g. CN=workspace) that fails verification. Pass --verify to
    enforce certificate checks (use this when pointing --base-url at production).

Env overrides: BASE_URL, FROM_LANG, TO_LANG, SAMPLE_TEXT, MAX_TIME
Requires: curl, and jq or python3.
USAGE
}

while [ $# -gt 0 ]; do
    case "$1" in
        --offline)   LIVE=0 ;;
        --insecure|-k) INSECURE=1 ;;
        --verify)    INSECURE=0 ;;
        --base-url)  BASE_URL="${2:?--base-url needs a value}"; shift ;;
        -h|--help)   usage; exit 0 ;;
        *)           echo "Unknown option: $1" >&2; usage; exit 2 ;;
    esac
    shift
done

BASE_URL="${BASE_URL%/}"
ENDPOINT="$BASE_URL/v1/translations"

if ! command -v curl >/dev/null 2>&1; then
    echo "error: curl is required" >&2
    exit 2
fi

if command -v jq >/dev/null 2>&1; then
    JSON_TOOL=jq
elif command -v python3 >/dev/null 2>&1; then
    JSON_TOOL=python3
else
    echo "error: jq or python3 is required to parse JSON responses" >&2
    exit 2
fi

if [ -t 1 ]; then
    GREEN=$'\033[32m'; RED=$'\033[31m'; YELLOW=$'\033[33m'; BOLD=$'\033[1m'; DIM=$'\033[2m'; RESET=$'\033[0m'
else
    GREEN=''; RED=''; YELLOW=''; BOLD=''; DIM=''; RESET=''
fi

CURL_COMMON=()
if [ "$INSECURE" = 1 ]; then
    CURL_COMMON+=(-k)
fi

PASS=0
FAIL=0
REQUESTS=0

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

BODY_FILE="$TMP_DIR/body"
ERR_FILE="$TMP_DIR/curl.err"
LAST_STATUS=000
LAST_BODY=''

pass() {
    PASS=$((PASS + 1))
    printf '  %sPASS%s %s\n' "$GREEN" "$RESET" "$1"
}

fail() {
    FAIL=$((FAIL + 1))
    printf '  %sFAIL%s %s\n' "$RED" "$RESET" "$1"
    if [ -n "${2:-}" ]; then
        printf '       %s\n' "$2"
    fi
}

short() {
    printf '%s' "$1" | tr '\n' ' ' | cut -c1-300
}

# describe_payload <payload> — one-line summary of the from/to/tone being sent.
describe_payload() {
    local summary=''

    if [ "$JSON_TOOL" = jq ]; then
        summary=$(printf '%s' "$1" | jq -r '
            if type == "object" then
                "from=\(.from // "«missing»") → to=\(.to // "«missing»")  tone=\(.tone // "none")  text=\((.text // "«missing»") | tostring | .[0:40])"
            else
                "not an object: \(.)"
            end' 2>/dev/null)
    fi

    printf '%s' "${summary:-unparsable: $(printf '%s' "$1" | cut -c1-60)}"
}

# check_quota — a 429 makes every remaining assertion meaningless, so stop with
# an explanation instead of a wall of confusing failures.
check_quota() {
    [ "$LAST_STATUS" = 429 ] || return 0

    printf '\n  %sHourly quota exhausted for this IP.%s\n' "$RED" "$RESET"
    printf '  The limit is shared by /gitmeh, /v1/chat/completions and /v1/translations,\n'
    printf '  and the 4xx checks below consume it too (the limiter runs first).\n'
    printf '  Wait for the next clock hour, or clear the bucket server-side:\n'
    printf '    redis-cli HDEL "api:hits:%s" 127.0.0.1\n' "$(date -u +%Y-%m-%d-%H)"
    printf '  Aborting: every remaining request would fail with 429 as well.\n\n'
    exit 3
}

# post <payload> — POST a JSON body, store status + body in LAST_*, echo what was sent.
post() {
    printf '  %s· %s%s\n' "$DIM" "$(describe_payload "$1")" "$RESET"
    REQUESTS=$((REQUESTS + 1))
    LAST_STATUS=$(curl -sS "${CURL_COMMON[@]}" --max-time "$MAX_TIME" -o "$BODY_FILE" -w '%{http_code}' \
        -H 'Content-Type: application/json' -X POST "$ENDPOINT" \
        --data-binary "$1" 2>"$ERR_FILE") || LAST_STATUS=000
    LAST_BODY=$(cat "$BODY_FILE" 2>/dev/null || true)
    check_quota
}

# post_file <path> — same, but streams a file (used for the 2 MB body test).
post_file() {
    printf '  %s· %s%s\n' "$DIM" "${2:-streaming file body}" "$RESET"
    REQUESTS=$((REQUESTS + 1))
    LAST_STATUS=$(curl -sS "${CURL_COMMON[@]}" --max-time "$MAX_TIME" -o "$BODY_FILE" -w '%{http_code}' \
        -H 'Content-Type: application/json' -X POST "$ENDPOINT" \
        --data-binary @"$1" 2>"$ERR_FILE") || LAST_STATUS=000
    LAST_BODY=$(cat "$BODY_FILE" 2>/dev/null || true)
    check_quota
}

# json <jq-filter> — extract a value from the last response ("" when absent).
json() {
    if [ "$JSON_TOOL" = jq ]; then
        printf '%s' "$LAST_BODY" | jq -r "$1 // empty" 2>/dev/null
    else
        printf '%s' "$LAST_BODY" | python3 -c '
import json, sys
path = sys.argv[1]
try:
    data = json.load(sys.stdin)
except Exception:
    sys.exit(0)
for part in path.strip(".").split("."):
    if isinstance(data, dict) and part in data:
        data = data[part]
    else:
        sys.exit(0)
print("" if data is None else data if isinstance(data, str) else json.dumps(data))
' "$1"
    fi
}

expect_status() { # <label> <expected>
    if [ "$LAST_STATUS" = "$2" ]; then
        pass "$1 (HTTP $2)"
    else
        fail "$1" "expected HTTP $2, got $LAST_STATUS — $(short "$LAST_BODY")"
    fi
}

expect_field() { # <label> <field> <expected>
    local actual
    actual=$(json ".$2")
    if [ "$actual" = "$3" ]; then
        pass "$1"
    else
        fail "$1" "expected $2=\"$3\", got \"$actual\" — $(short "$LAST_BODY")"
    fi
}

show_translation() { # prints the echoed from/to and a preview of the translation
    printf '       %s%s → %s%s: %s\n' \
        "$BOLD" "$(json .from)" "$(json .to)" "$RESET" \
        "$(json .translation | tr '\n' ' ' | cut -c1-110)"
}

expect_nonempty() { # <label> <field>
    local actual
    actual=$(json ".$2")
    if [ -n "$actual" ]; then
        pass "$1"
    else
        fail "$1" "expected a non-empty \"$2\" — $(short "$LAST_BODY")"
    fi
}

payload() { # <from> <to> <text> [tone]
    if command -v jq >/dev/null 2>&1; then
        jq -cn --arg f "$1" --arg t "$2" --arg x "$3" --arg tone "${4:-}" \
            '{from:$f, to:$t, text:$x} + (if $tone == "" then {} else {tone:$tone} end)'
    else
        python3 -c '
import json, sys
d = {"from": sys.argv[1], "to": sys.argv[2], "text": sys.argv[3]}
if len(sys.argv) > 4 and sys.argv[4]:
    d["tone"] = sys.argv[4]
print(json.dumps(d))
' "$1" "$2" "$3" "${4:-}"
    fi
}

printf '\n%sTranslations endpoint smoke test%s\n' "$BOLD" "$RESET"
printf '  endpoint : %s\n' "$ENDPOINT"
printf '  mode     : %s\n' "$([ "$LIVE" = 1 ] && echo 'live (validation + real model calls)' || echo 'offline (validation only)')"
if [ "$INSECURE" = 1 ]; then
    printf '  TLS      : %sverification OFF%s (self-signed dev cert; use --verify to enforce)\n\n' "$YELLOW" "$RESET"
else
    printf '  TLS      : verification ON\n\n'
fi

# ---------------------------------------------------------------- connectivity
printf '%s== Routing ==%s\n' "$BOLD" "$RESET"
printf '  %s· GET /v1/translations (no body; expects 405)%s\n' "$DIM" "$RESET"

LAST_STATUS=$(curl -sS "${CURL_COMMON[@]}" --max-time 10 -o "$BODY_FILE" -w '%{http_code}' "$ENDPOINT" 2>"$ERR_FILE") || LAST_STATUS=000
LAST_BODY=$(cat "$BODY_FILE" 2>/dev/null || true)

if [ "$LAST_STATUS" = 000 ]; then
    ERR_MSG=$(cat "$ERR_FILE" 2>/dev/null)
    printf '  %sCannot reach %s%s\n' "$RED" "$BASE_URL" "$RESET"

    case "$ERR_MSG" in
        *SSL*|*certificate*|*self-signed*)
            printf '  TLS verification failed: certificate is self-signed, expired, or issued for another name.\n'
            if [ "$INSECURE" = 0 ]; then
                printf '  Expected with --verify on a local dev host: drop --verify to bypass the check.\n'
                printf '  Check what is being served with: curl -kv %s/\n' "$BASE_URL"
            else
                printf '  -k is already applied, so this is a protocol-level TLS failure, not a CA issue.\n'
            fi
            ;;
        *)
            printf '  Start it with: APP_ENV=dev php -S 127.0.0.1:8000 -t public\n'
            ;;
    esac

    printf '  curl: %s\n' "$ERR_MSG"
    exit 2
fi

expect_status 'GET is rejected' 405
expect_field  'GET error code is method_not_allowed' error.code method_not_allowed
if [ "$(curl -sS "${CURL_COMMON[@]}" -o /dev/null -D - "$ENDPOINT" 2>/dev/null | tr -d '\r' | awk 'tolower($1)=="allow:"{print $2}')" = "POST" ]; then
    pass '405 advertises Allow: POST'
else
    fail '405 advertises Allow: POST'
fi

# ------------------------------------------------------------------ happy path
if [ "$LIVE" = 1 ]; then
    printf '\n%s== Happy path (real model calls) ==%s\n' "$BOLD" "$RESET"

    post "$(payload "$FROM_LANG" "$TO_LANG" "$SAMPLE_TEXT")"
    expect_status    'no tone returns 200' 200
    expect_field     'response echoes from' from "$FROM_LANG"
    expect_field     'response echoes to' to "$TO_LANG"
    expect_field     'omitted tone is reported as "none"' tone none
    expect_nonempty  'translation is non-empty' translation
    expect_nonempty  'model is reported' model
    show_translation

    for tone in formal informal simple kitten; do
        post "$(payload "$FROM_LANG" "$TO_LANG" "$SAMPLE_TEXT" "$tone")"
        expect_status   "tone=$tone returns 200" 200
        expect_field    "tone=$tone is echoed back" tone "$tone"
        expect_nonempty "tone=$tone translation is non-empty" translation
        show_translation
    done

    post "$(payload "$FROM_LANG" "$TO_LANG" "$SAMPLE_TEXT" 'NONE')"
    expect_status 'literal "NONE" is accepted as no tone' 200
    expect_field  'literal "NONE" normalises to "none"' tone none
    show_translation
else
    printf '\n%s== Happy path ==%s\n' "$BOLD" "$RESET"
    printf '  %sSKIP%s live model calls (--offline)\n' "$YELLOW" "$RESET"
fi

# -------------------------------------------------------------------- validation
printf '\n%s== Validation (no model call) ==%s\n' "$BOLD" "$RESET"

post "{\"to\":\"$TO_LANG\",\"text\":\"hello\"}"
expect_status 'missing from rejected' 400
expect_field  'missing from code' error.code invalid_from

post "{\"from\":\"$FROM_LANG\",\"text\":\"hello\"}"
expect_status 'missing to rejected' 400
expect_field  'missing to code' error.code invalid_to

post "{\"from\":\"   \",\"to\":\"$TO_LANG\",\"text\":\"hello\"}"
expect_status 'blank from rejected' 400
expect_field  'blank from code' error.code invalid_from

post "{\"from\":\"12345\",\"to\":\"$TO_LANG\",\"text\":\"hello\"}"
expect_status 'letter-less from rejected' 400
expect_field  'letter-less from code' error.code invalid_from

post "{\"from\":42,\"to\":\"$TO_LANG\",\"text\":\"hello\"}"
expect_status 'non-string from rejected' 400
expect_field  'non-string from code' error.code invalid_from

post "{\"from\":\"$FROM_LANG\",\"to\":\"!!!\",\"text\":\"hello\"}"
expect_status 'letter-less to rejected' 400
expect_field  'letter-less to code' error.code invalid_to

post "{\"from\":\"$FROM_LANG\",\"to\":\"$TO_LANG\"}"
expect_status 'missing text rejected' 400
expect_field  'missing text code' error.code invalid_text

post "{\"from\":\"$FROM_LANG\",\"to\":\"$TO_LANG\",\"text\":\"   \"}"
expect_status 'blank text rejected' 400
expect_field  'blank text code' error.code invalid_text

post "{\"from\":\"$FROM_LANG\",\"to\":\"$TO_LANG\",\"text\":\"hi\",\"tone\":[\"formal\"]}"
expect_status 'tone array rejected' 400
expect_field  'tone array code' error.code invalid_tone

post "{\"from\":\"$FROM_LANG\",\"to\":\"$TO_LANG\",\"text\":\"hi\",\"tone\":\"loud\"}"
expect_status 'unknown tone rejected' 400
expect_field  'unknown tone code' error.code invalid_tone

post "{\"from\":\"$FROM_LANG\",\"to\":\"$TO_LANG\",\"text\":\"hi\",\"tone\":7}"
expect_status 'numeric tone rejected' 400
expect_field  'numeric tone code' error.code invalid_tone

post '{oops'
expect_status 'malformed JSON rejected' 400
expect_field  'malformed JSON code' error.code invalid_json

post '[1,2]'
expect_status 'JSON list rejected' 400
expect_field  'JSON list code' error.code invalid_json

# ------------------------------------------------------------------------ 413
printf '\n%s== Body size cap ==%s\n' "$BOLD" "$RESET"

BIG_FILE="$TMP_DIR/big.json"
{
    printf '{"from":"English","to":"French","text":"'
    head -c 2200000 /dev/zero | tr '\0' 'a'
    printf '"}'
} > "$BIG_FILE"
post_file "$BIG_FILE" 'from=English → to=German  text=2.2 MB of "a"  (body-size probe)'
expect_status 'body over 2 MB rejected' 413
expect_field  'body over 2 MB code' error.code request_too_large
rm -f "$BIG_FILE"

# ---------------------------------------------------------------------- summary
printf '\n%s== Summary ==%s\n' "$BOLD" "$RESET"
printf '  %d passed, %d failed (%d requests; the POSTs count against the shared hourly quota)\n\n' \
    "$PASS" "$FAIL" "$REQUESTS"

[ "$FAIL" -eq 0 ]
