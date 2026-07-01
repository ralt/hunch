<?php

namespace App;

/**
 * Maps Upsun's runtime wiring into the env vars the app expects, so the same
 * code works locally and on Upsun with no config changes:
 *
 *   - PLATFORM_RELATIONSHIPS["meilisearch"] -> MEILI_URL  (the "search" app)
 *   - MEILI_MASTER_KEY                      -> MEILISEARCH_API_KEY
 *
 * Called from public/index.php and bin/console before the kernel boots, so
 * %env(MEILI_URL)% / %env(MEILISEARCH_API_KEY)% resolve correctly. No-op off Upsun.
 */
final class Platform
{
    public static function bootstrap(): void
    {
        if (false === ($rels = getenv('PLATFORM_RELATIONSHIPS'))) {
            // Not on Upsun — leave .env values in place.
        } else {
            $decoded = json_decode(base64_decode($rels), true) ?: [];

            // Meilisearch (the "search" app) -> MEILI_URL.
            $meili = $decoded['meilisearch'][0] ?? null;
            if (\is_array($meili)) {
                self::set('MEILI_URL', $meili['url'] ?? \sprintf(
                    '%s://%s:%s',
                    $meili['scheme'] ?? 'http',
                    $meili['host'] ?? '127.0.0.1',
                    $meili['port'] ?? 7700,
                ));
            }

            // PostgreSQL service -> DATABASE_URL.
            $db = $decoded['database'][0] ?? null;
            if (\is_array($db)) {
                self::set('DATABASE_URL', \sprintf(
                    'postgresql://%s:%s@%s:%s/%s?serverVersion=16&charset=utf8',
                    rawurlencode((string) ($db['username'] ?? 'main')),
                    rawurlencode((string) ($db['password'] ?? '')),
                    $db['host'] ?? 'database.internal',
                    $db['port'] ?? 5432,
                    $db['path'] ?? 'main',
                ));
            }
        }

        // Share one project secret across both apps.
        if (!getenv('MEILISEARCH_API_KEY') && ($key = getenv('MEILI_MASTER_KEY'))) {
            self::set('MEILISEARCH_API_KEY', $key);
        }
    }

    private static function set(string $name, string $value): void
    {
        putenv("$name=$value");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
