#!/bin/sh
# Warms the prod cache with the *runtime* environment (real APP_SECRET, real
# DATABASE_URL) and then hands over to the stock FrankenPHP entrypoint, which
# turns ["--config", ...] into "frankenphp run --config ...".
set -e

if [ "${APP_ENV:-dev}" = "prod" ]; then
    php bin/console cache:warmup
fi

exec docker-php-entrypoint "$@"
