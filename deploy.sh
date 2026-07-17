#!/usr/bin/env bash
set -euo pipefail

SSH_HOST="ryan@hellyer.kiwi"
DEPLOY_PATH="/var/www/ai.hellyer.kiwi"

echo "==> Deploying to $SSH_HOST:$DEPLOY_PATH"

ssh "$SSH_HOST" "cd $DEPLOY_PATH && \
    git pull && \
    composer install --no-dev --optimize-autoloader && \
    php bin/console doctrine:migrations:migrate --no-interaction && \
    php bin/console cache:clear --no-warmup && \
    php bin/console cache:warmup"

echo "==> Done."
