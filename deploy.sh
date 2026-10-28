#!/bin/bash
#
# Deploy to ai.hellyer.kiwi: push the working tree over rsync, then run the
# post-deploy build over SSH. Mirrors deployTEST.sh, but for this Symfony app.
#
# Usage:
#   ./deploy.sh
#
# Env overrides: PROD_HOST, PROD_PATH, SITE_URL

PROD_HOST="${PROD_HOST:-ryan@hellyer.kiwi}"
PROD_PATH="${PROD_PATH:-~/www/ai.hellyer.kiwi/}"
SITE_URL="${SITE_URL:-https://ai.hellyer.kiwi}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

EXCLUDES=(
    --exclude='.env'
    --exclude='.env.*'
    --exclude='.env.local'
    --exclude='.env.local.php'
    --exclude='.git/'
    --exclude='.gitignore'
    --exclude='.phpunit.cache/'
    --exclude='.phpunit.result.cache'
    --exclude='phpunit.xml'
    --exclude='var/'
    --exclude='vendor/'
    --exclude='node_modules/'
    --exclude='public/bundles/'
    --exclude='assets/vendor/'
    --exclude='access.log'
    --exclude='*.log'
)

echo "Deploying to ${PROD_HOST}:${PROD_PATH}..."

# A non-interactive SSH session does not source ~/.bashrc or ~/.profile, so
# tools installed under $HOME (.local/bin, .composer/vendor/bin, ...) are not
# on PATH. Prepend the usual locations. Confirmed on hellyer.kiwi:
#   composer -> /home/ryan/.local/bin/composer
REMOTE_PATH='export PATH="$HOME/.local/bin:$HOME/.composer/vendor/bin:$HOME/.config/composer/vendor/bin:/usr/local/bin:$PATH"'

# Preflight: verify the remote toolchain resolves in the same non-interactive
# shell the deploy uses. Catches "command not found" before any work happens.
echo "Checking remote toolchain..."
if ! ssh "${PROD_HOST}" "${REMOTE_PATH} && command -v php >/dev/null && command -v composer >/dev/null"; then
    echo "" >&2
    echo "x Preflight FAILED: php and/or composer not found on ${PROD_HOST}." >&2
    echo "  Remote PATH:  $(ssh "${PROD_HOST}" 'echo $PATH' 2>/dev/null)" >&2
    echo "  php:       $(ssh "${PROD_HOST}" "${REMOTE_PATH} && command -v php" 2>/dev/null || echo 'NOT FOUND')" >&2
    echo "  composer:  $(ssh "${PROD_HOST}" "${REMOTE_PATH} && command -v composer" 2>/dev/null || echo 'NOT FOUND')" >&2
    echo "  Add whichever directory is missing to REMOTE_PATH in this script." >&2
    exit 1
fi
echo "OK Remote toolchain OK"

rsync -avz --delete "${EXCLUDES[@]}" \
    "${SCRIPT_DIR}/" \
    "${PROD_HOST}:${PROD_PATH}"

echo ""
echo "Running post-deploy commands..."
if ! ssh "${PROD_HOST}" "${REMOTE_PATH} && cd ${PROD_PATH} && composer install --no-dev --optimize-autoloader && php -r 'opcache_reset();' && php bin/console doctrine:migrations:migrate --no-interaction && php bin/console cache:clear --no-warmup && php bin/console cache:warmup && php bin/console assets:install public && php bin/console importmap:install"; then
    echo "" >&2
    echo "x Post-deploy commands FAILED on ${PROD_HOST}." >&2
    echo "  Re-run the failing step by hand to see the full error:" >&2
    echo "    ssh ${PROD_HOST} '${REMOTE_PATH} && cd ${PROD_PATH} && <command>'" >&2
    exit 1
fi

# Smoke test: the endpoint must answer, and a 405 on GET proves routing +
# the error serializer are wired up in prod.
echo ""
echo "Running API smoke test..."
SMOKE_URL="${SITE_URL}/v1/translations"
SMOKE_STATUS="$(curl -sS -o /dev/null -w '%{http_code}' "${SMOKE_URL}" 2>/dev/null || echo 000)"
if [ "${SMOKE_STATUS}" != "405" ]; then
    if [ "${SMOKE_STATUS}" = "000" ]; then
        echo "x API smoke test FAILED: cannot reach ${SMOKE_URL}" >&2
    else
        echo "x API smoke test FAILED: ${SMOKE_URL} returned HTTP ${SMOKE_STATUS} (expected 405 for GET)" >&2
    fi
    exit 1
fi
echo "OK API smoke test passed (HTTP 405 on GET, as expected: ${SMOKE_URL})"

echo ""
echo "✓ Deploy complete!"