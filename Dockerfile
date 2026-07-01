# Local dev image for the Symfony app. webklex/php-imap is pure PHP (no ext-imap
# needed); we add intl + mbstring which Symfony/webklex want.
FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libicu-dev libonig-dev libzip-dev libpq-dev \
    && docker-php-ext-install intl mbstring zip pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . /app

RUN composer install --no-interaction --no-progress --prefer-source \
    && mkdir -p var && chmod -R 0777 var

EXPOSE 8000
# Waits for Postgres, applies the schema, then serves (index.php is the router).
CMD ["sh", "docker-entrypoint.sh"]
