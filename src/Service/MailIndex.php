<?php

namespace App\Service;

use Meilisearch\Client;

/**
 * Thin wrapper over Meilisearch. Crucially, embeddings are produced *inside*
 * Meilisearch by its on-device HuggingFace embedder (source: huggingFace) — no
 * Python, no Ollama, nothing leaves the box. We talk to Meilisearch directly
 * (rather than via symfony/ai-store, which forces client-side vectors) precisely
 * to keep the embedding local.
 */
final class MailIndex
{
    private Client $client;
    private bool $ready = false;

    public function __construct(
        string $url,
        ?string $apiKey,
        private readonly string $indexName,
        private readonly string $embedModel,
    ) {
        $this->client = new Client($url, $apiKey ?: null);
    }

    /** Create the index + settings (idempotent). Called lazily before use. */
    public function ensure(): void
    {
        if ($this->ready) {
            return;
        }
        try {
            $task = $this->client->createIndex($this->indexName, ['primaryKey' => 'id']);
            $this->client->waitForTask($task['taskUid']);
        } catch (\Throwable) {
            // Index already exists — fine.
        }

        $task = $this->client->index($this->indexName)->updateSettings([
            'searchableAttributes' => ['subject', 'from', 'to', 'body'],
            'filterableAttributes' => ['userId', 'mailboxId', 'folder', 'from', 'dateUnix'],
            'sortableAttributes' => ['dateUnix'],
            // Default is 1000, which caps estimatedTotalHits (our per-mailbox
            // count) and search depth. Raise it so counts reflect reality.
            'pagination' => ['maxTotalHits' => 1000000],
            'embedders' => [
                'default' => [
                    'source' => 'huggingFace',
                    'model' => $this->embedModel,
                    'documentTemplate' => "Subject: {{doc.subject}}\nFrom: {{doc.from}}\n\n{{doc.body}}",
                    'documentTemplateMaxBytes' => 2048,
                ],
            ],
        ]);
        // Setting the embedder makes Meilisearch download the model (~400MB) on
        // first use — allow plenty of time.
        $this->client->waitForTask($task['taskUid'], 600 * 1000, 1000);
        $this->ready = true;
    }

    /** @param array<int,array<string,mixed>> $docs */
    public function add(array $docs): void
    {
        if (!$docs) {
            return;
        }
        $this->ensure();
        $task = $this->client->index($this->indexName)->addDocuments($docs, 'id');
        $this->client->waitForTask($task['taskUid'], 600 * 1000, 100);
    }

    /**
     * Hybrid search, always scoped to one user. $semanticRatio: 0 = keyword
     * only, 1 = meaning only.
     *
     * @return array<int,array<string,mixed>> hits, each with a "snippet" key
     */
    public function search(string $userId, string $query, string $from = '', string $after = '', string $before = '', float $semanticRatio = 0.5): array
    {
        $this->ensure();
        $q = trim($query.' '.$from);

        $filters = ['userId = "'.$userId.'"'];
        if ($after && ($t = strtotime($after))) {
            $filters[] = 'dateUnix >= '.$t;
        }
        if ($before && ($t = strtotime($before))) {
            $filters[] = 'dateUnix <= '.($t + 86400);
        }

        $params = [
            'limit' => 15,
            'hybrid' => ['embedder' => 'default', 'semanticRatio' => $semanticRatio],
            'attributesToRetrieve' => ['id', 'userId', 'mailboxId', 'folder', 'uid', 'dateISO', 'dateUnix', 'from', 'fromName', 'to', 'subject'],
            'attributesToCrop' => ['body'],
            'cropLength' => 50,
            // Surface the 0..1 relevance score so the UI can rank/re-order live.
            'showRankingScore' => true,
        ];
        if ($filters) {
            $params['filter'] = implode(' AND ', $filters);
        }

        $hits = $this->client->index($this->indexName)->search($q, $params)->getHits();

        return array_map(static function (array $h): array {
            $h['snippet'] = $h['_formatted']['body'] ?? '';
            unset($h['_formatted']);

            return $h;
        }, $hits);
    }

    public function count(): int
    {
        $this->ensure();

        return (int) ($this->client->index($this->indexName)->stats()['numberOfDocuments'] ?? 0);
    }

    /**
     * Live count of indexed messages for one mailbox. Returns null if the index
     * is unavailable (so the page can still render).
     */
    public function countForMailbox(string $mailboxId): ?int
    {
        try {
            // Finite pagination (hitsPerPage/page) yields an exact totalHits,
            // unlike estimatedTotalHits which is approximate.
            $res = $this->client->index($this->indexName)->search('', [
                'filter' => 'mailboxId = "'.$mailboxId.'"',
                'hitsPerPage' => 1,
                'page' => 1,
            ]);

            return $res->getTotalHits() ?? $res->getEstimatedTotalHits();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Fetch one indexed message (including its body) by document id, for viewing.
     *
     * @return array<string,mixed>|null
     */
    public function getDocument(string $id): ?array
    {
        try {
            $doc = $this->client->index($this->indexName)->getDocument($id);

            return \is_array($doc) ? $doc : (array) $doc;
        } catch (\Throwable) {
            return null;
        }
    }
}
