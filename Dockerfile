# Local dev image for the Symfony app. webklex/php-imap is pure PHP (no ext-imap
# needed); we add intl + mbstring which Symfony/webklex want.
FROM php:8.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libicu-dev libonig-dev libzip-dev libpq-dev \
    && docker-php-ext-install intl mbstring zip pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
# Vendor lives in its own layer, keyed on composer.json alone: editing src/
# or config/ must not throw away — and re-download — every dependency, which
# is what made each `--build` restart take minutes. Dist zips, not
# --prefer-source git clones: same code, a fraction of the download time.
COPY composer.json /app/
RUN composer install --no-interaction --no-progress

COPY . /app
RUN composer dump-autoload --no-interaction \
    && mkdir -p var && chmod -R 0777 var

EXPOSE 8000
# Waits for Postgres, applies the schema, then serves (index.php is the router).
CMD ["sh", "docker-entrypoint.sh"]
