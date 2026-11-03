FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock symfony.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader

FROM php:8.3-apache-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl gosu libicu-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" intl opcache pcntl pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/foodjett-entrypoint
COPY . .
COPY --from=vendor /app/vendor ./vendor

RUN chmod +x /usr/local/bin/foodjett-entrypoint \
    && mkdir -p var/cache var/log public/uploads config/jwt \
    && chown -R www-data:www-data var public/uploads config/jwt

RUN { \
      echo 'upload_max_filesize=10M'; \
      echo 'post_max_size=12M'; \
      echo 'memory_limit=256M'; \
      echo 'opcache.enable=1'; \
      echo 'opcache.validate_timestamps=0'; \
    } > /usr/local/etc/php/conf.d/foodjett.ini

EXPOSE 80

ENTRYPOINT ["foodjett-entrypoint"]
CMD ["apache2-foreground"]
