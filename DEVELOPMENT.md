# Development

Running Hunch locally. For what it is, see the [README](README.md); for how it's built, see [ARCHITECTURE.md](ARCHITECTURE.md).

## Quick start (Docker)

```bash
docker compose up --build          # app + Postgres + Meilisearch + worker (+ Mercure)
docker compose exec app php bin/console hunch:user:create you@example.com --admin
#   open the printed activation link, set a password, log in
```

Then, in the app:

1. **Settings** → add your Anthropic API key.
2. **Mailboxes** → add an IMAP account (use **"Check settings"** first) → it syncs in the background.
3. **Search** → ask for an email in plain language.

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

## Deploying on Upsun

Hunch ships with an [Upsun](https://upsun.com) configuration (`.upsun/config.yaml`) that provisions three moving parts:

- **`app`** — the PHP application, with a `sync` worker (background mailbox sync) and a half-hourly `hunch:sync` cron.
- **`search`** — Meilisearch, run as a second application (it isn't a managed Upsun service): it downloads the binary at build time and serves on the internal network only.
- **`db`** — PostgreSQL 16.

Only `app` is public; `search` and `db` are reached over internal relationships, and `App\Platform` decodes those into `MEILI_URL` / `DATABASE_URL` at runtime.

### 1. Install the CLI and create a project

```bash
# https://docs.upsun.com/administration/cli.html
curl -fsS https://raw.githubusercontent.com/platformsh/cli/main/installer.sh | bash
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

> **Note — real-time updates.** The live search UI streams results over a [Mercure](https://mercure.rocks) hub. The bundled Upsun config does **not** yet provision one, so to get live streaming in production you'll need a Mercure hub reachable by the app and the browser, wired via `MERCURE_URL` and `MERCURE_PUBLIC_URL` (plus the JWT secrets Mercure expects). Search still runs without it; the page just won't update live.
