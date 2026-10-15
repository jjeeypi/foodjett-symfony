#!/bin/sh
set -eu

cd /var/www/html

mkdir -p var/cache var/log public/uploads config/jwt
chown -R www-data:www-data var public/uploads config/jwt

if [ ! -s config/jwt/private.pem ] || [ ! -s config/jwt/public.pem ]; then
    echo "Generating JWT signing keys..."
    gosu www-data php bin/console lexik:jwt:generate-keypair --skip-if-exists --no-interaction
fi

if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
    echo "Running Doctrine migrations..."
    gosu www-data php bin/console doctrine:migrations:migrate --no-interaction

    echo "Warming Symfony production cache..."
    gosu www-data php bin/console cache:clear --no-interaction
fi

if [ "${1:-}" = "php" ]; then
    exec gosu www-data "$@"
fi

exec "$@"
