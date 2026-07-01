# Hunch

**Hunch finds an email from a *hunch*** — a rough sense of what it was about, who might have sent it, roughly when — **without needing the right keywords or any precise detail**. Instead of matching search terms, you have a short **conversation**: you describe what you're after ("that document the bank sent me about the mortgage", "the insurance thing from the spring"), an AI agent searches and ranks by meaning, and when your description could point at several emails it asks a clarifying question and narrows in with you until you land on the one you meant.

You connect a mailbox, Hunch quietly indexes it, and then you just talk to it. The searching, the follow-up questions, and the ranking are all driven by the agent — you never have to guess the exact word that happens to be in the email.

**It's not an email client.** It doesn't send mail, it doesn't manage folders, it won't replace Gmail or Thunderbird. The most it does with an email is **show it to you** — read-only. That's the whole product.

## Why you'd want it

Search in most mail apps is a keyword lottery: you have to remember a word that's actually *in* the email. But you rarely remember the words — you remember the gist. Hunch is built for that gist.

- **Search by a hunch.** Describe the email the way you'd describe it to a colleague. No operators, no exact phrases, no guessing the subject line.
- **It's a conversation, not a query box.** Vague on purpose? Hunch asks a short, concrete question and narrows down with you, instead of dumping a thousand "results."
- **You watch it think.** Matches stream in and re-rank by relevance as the agent searches, laid out in a tidy set of panes — click a match to read it right beside the conversation.
- **Finds meaning across languages.** An English description can surface a French email about the same thing.
- **Private by design.** Your mail is indexed on your own machine; nothing is shipped off to an embedding service. Only your short question and a few snippets are ever sent to the AI to reason over.
- **Read-only and safe.** Hunch can find and show your mail. It can't send, delete, or change anything.

## Who it's for

- Anyone sitting on years of mail who can never find the one message that matters.
- Teams who want a private, self-hosted way to search their own inboxes.
- People who'd rather *ask* for an email than reverse-engineer the search box.

Accounts are invite-only (an admin provisions them — no open sign-up), and each person brings their own model: **Anthropic (Claude), OpenAI (GPT), or Ollama** to run inference locally on your own hardware with no API key at all.

## Try it

Hunch is self-hosted and deploys on [Symfony Cloud](https://symfony.com/cloud/). To run it yourself, see **[DEVELOPMENT.md](DEVELOPMENT.md)** for setup and **[ARCHITECTURE.md](ARCHITECTURE.md)** for how it's built and why.

## License

GNU General Public License v3.0 — see [LICENSE](LICENSE).
