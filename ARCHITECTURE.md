# Architecture

How Hunch is built and why. For what Hunch *is*, see the [README](README.md); for how to run it, see [DEVELOPMENT.md](DEVELOPMENT.md).

## Stack

- **[Symfony](https://symfony.com)** — application framework (controllers, Messenger, Doctrine, security).
- **[Symfony AI](https://github.com/symfony/ai)** — the agent, toolbox, and platform bridge that drive the conversational search.
- **[Meilisearch](https://www.meilisearch.com)** — the search index, with its built-in on-device HuggingFace embedder for hybrid keyword + semantic search.
- **[Mercure](https://mercure.rocks)** — real-time updates pushed to the browser over SSE.
- **PostgreSQL** — settings, users, and conversation history.
- **[Symfony Cloud](https://symfony.com/cloud/)** — deployment target (`.upsun/config.yaml`).

The AI provider is pluggable per user: Anthropic (Claude), OpenAI (GPT), or Ollama (local inference). See `App\Service\AgentFactory`.

## How it works

```
IMAP account ──(background worker)──▶ local Maildir (.eml + .json)
                                            │
                                            ▼
                              Meilisearch (on-device HuggingFace embedder)
                                   hybrid keyword + semantic, scoped per user
                                            │
                          Symfony AI Agent (Anthropic / OpenAI / Ollama)
                                   search → clarify → present
                                            │
                                   You: ask in plain language, view the results
```

- **Sync** — a Symfony Messenger worker pulls a mailbox over IMAP into a local Maildir (raw `.eml` for viewing + a `.json` sidecar for reindexing), pages through it incrementally, and pushes documents to Meilisearch.
- **Embeddings** — Meilisearch's built-in HuggingFace embedder vectorizes each message locally; a multilingual model means an English query finds a French email.
- **Search** — the Symfony AI Agent runs `search_emails` (hybrid keyword + semantic, filtered to your user), asks one clarifying question if needed, streams relevance-ranked candidates to the browser as it goes, and presents the best matches with a reason each.

## Design decisions

### Why a worker for sync

Syncing a real mailbox means fetching every message over IMAP — minutes to hours, not milliseconds. Doing that in the HTTP request path freezes a web worker and times out the browser (and OOMs on large mailboxes). Instead the request enqueues a job and returns immediately; a dedicated Symfony Messenger worker fetches and indexes in the background, paging in small batches and persisting a per-folder cursor so it resumes after a restart instead of starting over.

### Why on-device embeddings

This is personal mail — bank statements, insurance, legal documents. Sending all of it to a hosted embedding API is a privacy cost most people shouldn't pay for search. Meilisearch's HuggingFace embedder downloads a multilingual model and computes vectors locally, so indexing is fully private; only your short query and a handful of snippets ever reach the model provider during a search.

### Why real-time updates (Mercure)

A search is an agent loop — several model calls plus Meilisearch queries — so it can take tens of seconds. Holding an HTTP request open for that, then reloading the page, is the wrong experience. Hunch runs the search in the worker and **publishes progress and results to a Mercure hub**; the browser subscribes over SSE and the UI updates live, so the page never blocks and you see "searching…" then candidates stream in and re-rank. (Mercure also keeps PHP-FPM from tying up a worker per open connection.)

### Why Postgres holds settings, not emails

Postgres stores users, mailbox settings (with encrypted IMAP credentials), AI conversation history, and each conversation's candidate registry — the small, relational, must-not-lose-it state. The emails themselves stay on the filesystem as `.eml` + `.json`, so the search index is a derived artifact you can rebuild any time (`hunch:reindex`) without re-fetching over IMAP.

## Security & privacy notes

- **Per-user scoping.** Every search is filtered to the authenticated user's id (a server-set UUID, never taken from the model or user input), and email viewing re-checks ownership. See the search-isolation code in `MailIndex` and `EmailController`.
- **Encrypted secrets.** IMAP passwords and each user's AI provider key are encrypted at rest (libsodium, keyed from `APP_SECRET`).
- **Invite-only.** No open registration; admins provision users, who set a password via a one-time, single-use, expiring activation link.
- **Read-only.** The app can only read and display mail — no send, delete, or modify.
