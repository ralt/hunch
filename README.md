# Hunch

**Hunch is not an email client.** It doesn't send mail, it doesn't manage folders, it won't replace Gmail or Thunderbird. It does exactly one thing: it finds the email you only *half-remember* — "that document the bank sent me about the mortgage", "the insurance thing from the spring" — by **meaning**, not keywords, and asks you a clarifying question when your description is ambiguous.

You connect a mailbox, Hunch syncs it locally and embeds it **on-device**, and then you search it in plain language. The matching, the follow-up questions, and the ranking are done by a Claude-backed agent. The most it does with an email is **show it to you** — read-only. That's the whole product.

Built with **Symfony** and the **Symfony AI** components, deployable on **Upsun**.

## What it is / isn't

- ✅ Semantic, conversational search over your own mail ("find by a hunch").
- ✅ Local-first: emails live on your disk; embeddings are computed on-device (no email text sent to an embedding API).
- ✅ Multi-user, each bringing their own Anthropic API key.
- ✅ Read-only viewing of the emails it finds.
- ❌ Not a mail client — no sending, no replying, no folder management, no receiving.

## How it works

```
IMAP account ──(background worker)──▶ local Maildir (.eml + .json)
                                            │
                                            ▼
                              Meilisearch (on-device HuggingFace embedder)
                                   hybrid keyword + semantic, scoped per user
                                            │
                                   Symfony AI Agent (Claude)
                                   search → clarify → present
                                            │
                                   You: ask in plain language, view the results
```

- **Sync** — a Symfony Messenger worker pulls a mailbox over IMAP into a local Maildir (raw `.eml` for viewing + a `.json` sidecar for reindexing), pages through it incrementally, and pushes documents to Meilisearch.
- **Embeddings** — Meilisearch's built-in HuggingFace embedder vectorizes each message locally; a multilingual model means an English query finds a French email.
- **Search** — the Symfony AI Agent runs `search_emails` (hybrid keyword+semantic, filtered to your user), asks one clarifying question if needed, and presents the best matches with a reason each.

## Features

- **Conversational search** — describe the email vaguely; the agent reformulates, searches, and narrows with a question when there are distinct candidates.
- **On-device embeddings** — nothing leaves the box to be vectorized; the model runs inside Meilisearch.
- **Per-user, bring-your-own-key** — admins provision accounts (no open registration); each user sets their own Anthropic key, encrypted at rest (libsodium keyed from `APP_SECRET`).
- **Admin / invite flow** — admins create users; the user sets their password via a one-time, single-use, expiring activation link.
- **Background sync with visible status** — each mailbox shows an indexed count, sync status, and any error, right on the page.
- **Read-only viewing** — open a found email to read it; never more than that.

## Infrastructure architecture

### Why a worker for sync

Syncing a real mailbox means fetching every message over IMAP — minutes to hours, not milliseconds. Doing that in the HTTP request path freezes a web worker and times out the browser (and OOMs on large mailboxes). Instead the request enqueues a job and returns immediately; a dedicated Symfony Messenger worker fetches and indexes in the background, paging in small batches and persisting a per-folder cursor so it resumes after a restart instead of starting over.

### Why on-device embeddings

This is personal mail — bank statements, insurance, legal documents. Sending all of it to a hosted embedding API is a privacy cost most people shouldn't pay for search. Meilisearch's HuggingFace embedder downloads a multilingual model and computes vectors locally, so indexing is fully private; only your short query and a handful of snippets ever reach Claude during a search.

### Why real-time updates (Mercure)

A search is an agent loop — several Claude calls plus Meilisearch queries — so it can take tens of seconds. Holding an HTTP request open for that, then reloading the page, is the wrong experience. Hunch runs the search in the worker and **publishes progress and results to a Mercure hub**; the browser subscribes over SSE and the chat updates live, so the page never blocks and you see "searching…" then results stream in. (Mercure also keeps PHP-FPM from tying up a worker per open connection.)

### Why Postgres holds settings, not emails

Postgres stores users, mailbox settings (with encrypted IMAP credentials), and AI conversation history — the small, relational, must-not-lose-it state. The emails themselves stay on the filesystem as `.eml` + `.json`, so the search index is a derived artifact you can rebuild any time (`hunch:reindex`) without re-fetching over IMAP.

## Running it locally

See [`docs/`](docs/) for the project site. Quick start:

```bash
docker compose up --build          # app + Postgres + Meilisearch + worker (+ Mercure)
docker compose exec app php bin/console hunch:user:create you@example.com --admin
#   open the printed activation link, set a password, log in
```

Then: **Settings** → add your Anthropic key · **Mailboxes** → add an IMAP account (use "Check settings" first) → it syncs in the background · **Search**.

| Command | What it does |
|---|---|
| `hunch:sync` | Sync all mailboxes over IMAP → filesystem + index |
| `hunch:reindex` | Rebuild the search index from the on-disk emails (no IMAP) |
| `hunch:user:create <email> [--admin]` | Provision a user (activation link) |
| `hunch:meili` | Download + run Meilisearch locally (non-Docker dev) |

## License

MIT.
