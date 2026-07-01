#!/bin/sh
set -e

# Wait for Postgres by retrying the schema sync until it succeeds, then serve.
echo "Applying database schema (waiting for Postgres)..."
n=0
until php bin/console doctrine:schema:update --force --no-interaction 2>/dev/null; do
    n=$((n + 1))
    if [ "$n" -gt 60 ]; then
        echo "Database not reachable; last attempt (showing errors):"
        php bin/console doctrine:schema:update --force --no-interaction
        exit 1
    fi
    sleep 2
done
echo "Schema ready."

exec php -S 0.0.0.0:8000 -t public public/index.php
