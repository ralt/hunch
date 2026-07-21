# Development

Running Hunch locally. For what it is, see the [README](README.md); for how it's built, see [ARCHITECTURE.md](ARCHITECTURE.md).

## Quick start (Docker)

```bash
docker compose up --build          # app + Postgres + Meilisearch + worker (+ Mercure)
docker compose exec app php bin/console hunch:user:create you@example.com --admin
#   open the printed activation link, set a password, log in
```

Then, in the app:

1. **Settings** → pick your AI provider (Anthropic, OpenAI, or Ollama for local inference) and add its key.
2. **Mailboxes** → add an IMAP account (use **"Check settings"** first) → it syncs in the background.
3. **Search** → ask for an email in plain language.

## Hybrid mode: app on the host, infra in Docker

If your IMAP server only listens on the host's loopback (Proton Bridge binds
`127.0.0.1:1143` and offers no way to listen wider), the containerized app can
never reach it. Run the app and workers directly on the host instead, keeping
Postgres/Meilisearch/Mercure in Docker:

```bash
# infra only — an override must publish Postgres (e.g. "127.0.0.1:5432:5432")
docker compose up -d db meili mercure
composer install                      # PHP >= 8.4 with intl/mbstring/zip/pdo_pgsql
cp .env .env.local                    # defaults already point at 127.0.0.1
php bin/console doctrine:schema:update --force
php -S 127.0.0.1:8000 -t public public/index.php   # + the two messenger:consume workers
```

Mind two migration gotchas: keep the same `APP_SECRET` (it encrypts stored
IMAP passwords) and move `var/maildir` out of the old `maildir` docker volume
if you had synced mail. Mailbox hosts change meaning too: from the host,
Proton Bridge is plain `127.0.0.1`, not `host.docker.internal`.

## Console commands

Run inside the app container (`docker compose exec app php bin/console <command>`):

| Command | What it does |
|---|---|
| `hunch:sync` | Sync all mailboxes over IMAP → filesystem + index |
| `hunch:reindex` | Rebuild the search index from the on-disk emails (no IMAP) |
| `hunch:user:create <email> [--admin]` | Provision a user (prints an activation link) |
| `hunch:meili` | Download + run Meilisearch locally (non-Docker dev) |

## Services & ports

| Service | URL |
|---|---|
| App | <http://localhost:8000> |
| Mercure hub | <http://localhost:1337> |
| Meilisearch | <http://localhost:7700> |

Useful while developing:

```bash
docker compose logs -f sync-worker     # watch a mailbox sync
docker compose logs -f worker          # watch search jobs
curl -s localhost:7700/indexes/hunch/stats | grep numberOfDocuments   # index size
```

## Configuration

Environment is set in `.env` (committed defaults; put secrets in `.env.local`, which is gitignored). Key variables:

- `DATABASE_URL` — PostgreSQL connection.
- `MEILI_URL` / `MEILI_INDEX` — Meilisearch endpoint and index name.
- `EMBED_MODEL` — the HuggingFace embedding model Meilisearch runs on-device.
- `MESSENGER_TRANSPORT_DSN` — background job queue (Doctrine/Postgres by default).
- `MERCURE_URL` / `MERCURE_PUBLIC_URL` — the Mercure hub (internal + browser-facing).
- `APP_SECRET` — also the key material for encrypting IMAP passwords and API keys.

IMAP accounts and each user's AI provider key are configured **in the app** (per user), not via environment.

## Tests

Fast, dependency-light regression tests (PHPUnit) cover the areas most likely
to break: the SSRF guard, per-conversation result ranking/dedup, credential
encryption, and the search tool's argument boundary.

```bash
docker compose exec app vendor/bin/phpunit
```

Set `HUNCH_STRICT_SSRF=1` in a multi-tenant deployment to also block
private/loopback hosts for the user-supplied Ollama URL and IMAP host (default
`0`, since self-hosted setups legitimately use localhost).

## Deploying on Symfony Cloud

Hunch ships with a [Symfony Cloud](https://symfony.com/cloud/) configuration (`.upsun/config.yaml`) that provisions these moving parts:

- **`app`** — the PHP application, with `search` and `sync` workers (isolated so a long mailbox sync never blocks interactive search) and a half-hourly `hunch:sync` cron.
- **`search`** — Meilisearch, run as a second application (it isn't a managed Symfony Cloud service): it downloads the binary at build time and serves on the internal network only.
- **`db`** — PostgreSQL 16.
- **`mercure`** — a managed [Mercure](https://mercure.rocks) hub for the live search stream, exposed to the browser on the same origin at `/.well-known/mercure`.

Only `app` is public; `search`, `db`, and `mercure` are reached over internal relationships, and `App\Platform` decodes those into `MEILI_URL` / `DATABASE_URL` / `MERCURE_URL` (+ JWT secret) at runtime.

### 1. Install the CLI and create a project

```bash
# https://symfony.com/cloud/
curl -fs https://get.symfony.com/cloud/configurator | bash
upsun auth:login
upsun project:create --title hunch          # then link this repo:
upsun project:set-remote <project-id>
```

### 2. Set project variables

Both apps share a Meilisearch master key, and the app needs a secret for encrypting stored credentials:

```bash
# Shared Meilisearch master key (used by app + search)
upsun variable:create --level project --name env:MEILI_MASTER_KEY \
    --value "$(openssl rand -hex 24)" --sensitive true

# Symfony secret — also the key material for encrypting IMAP passwords and API keys
upsun variable:create --level project --name env:APP_SECRET \
    --value "$(openssl rand -hex 16)" --sensitive true

upsun variable:create --level project --name env:APP_ENV --value prod
```

### 3. Give the search app enough memory

Meilisearch's on-device embedder loads a ~400 MB model into RAM and embeds on CPU. Size the `search` app accordingly (or point `MEILI_URL` at an external Meilisearch and drop the `search` app):

```bash
upsun resources:set --size search:1G     # adjust to your plan
```

### 4. Deploy

```bash
git push upsun main
```

The build downloads Meilisearch and runs `composer install`; the deploy hook applies the database schema (`doctrine:schema:update`) and clears the cache.

### 5. Create the first admin and log in

```bash
upsun ssh -A app -- php bin/console hunch:user:create you@example.com --admin
# open the printed activation link, set a password, then add your API key + a mailbox in the app
```

Real-time search updates work out of the box: the `mercure` service is provisioned by the config and wired automatically — no extra variables to set.
