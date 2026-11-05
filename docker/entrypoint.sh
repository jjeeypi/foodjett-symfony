#!/bin/sh
set -eu

cd /var/www/html

mkdir -p var/cache var/log var/uploads public/uploads
chown -R www-data:www-data var public/uploads

if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
    echo "Running Doctrine migrations..."
    gosu www-data php bin/console doctrine:migrations:migrate --no-interaction

    echo "Warming Symfony production cache..."
    gosu www-data php bin/console cache:clear --no-interaction

    # Optional demo data: the 50-restaurant catalog. It skips restaurants that already exist,
    # so it is safe on every start and only does work on a fresh database.
    if [ -n "${SEED_CATALOG_PASSWORD:-}" ]; then
        echo "Loading the demo restaurant catalog..."
        gosu www-data php bin/console app:seed-catalog --password="$SEED_CATALOG_PASSWORD" --no-interaction
    fi
fi

if [ "${1:-}" = "php" ]; then
    exec gosu www-data "$@"
fi

exec "$@"
