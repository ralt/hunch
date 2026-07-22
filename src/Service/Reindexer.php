<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;

/**
 * Rebuilds the Meilisearch index from the on-disk JSON sidecars — no IMAP. Use
 * after changing the embedding model or resetting the index. With $reparse,
 * also re-extracts each body from the sibling .eml (offline MIME parse) and
 * rewrites the sidecar — for when the extraction logic itself was fixed, e.g.
 * <style> CSS leaking into HTML-only bodies.
 */
final class Reindexer
{
    public function __construct(
        private readonly MailIndex $index,
        private readonly Maildir $store,
        #[Autowire('%kernel.project_dir%/var/maildir')] private readonly string $maildir,
    ) {
    }

    /** @param callable(string):void $progress @return int documents reindexed */
    public function reindexAll(callable $progress, bool $reparse = false): int
    {
        if (!is_dir($this->maildir)) {
            return 0;
        }
        $total = 0;
        $batch = [];
        foreach ((new Finder())->files()->in($this->maildir)->name('*.json') as $file) {
            $doc = json_decode($file->getContents(), true);
            if (!\is_array($doc) || !isset($doc['id'])) {
                continue;
            }
            if ($reparse) {
                $doc = $this->reparseBody($doc, $file->getPathname(), $progress);
            }
            $batch[] = $doc;
            if (\count($batch) >= 200) {
                $this->index->add($batch);
                $total += \count($batch);
                $batch = [];
                $progress("reindexed $total");
            }
        }
        if ($batch) {
            $this->index->add($batch);
            $total += \count($batch);
        }
        $progress("done: $total documents");

        return $total;
    }

    /**
     * Re-extract the body from the sibling .eml and persist the corrected
     * sidecar. Keeps the stored body when there is no .eml or parsing fails.
     *
     * @param array<string,mixed> $doc
     * @param callable(string):void $progress
     *
     * @return array<string,mixed>
     */
    private function reparseBody(array $doc, string $jsonPath, callable $progress): array
    {
        // Sibling path rather than Maildir::readBody(): the Finder walked the
        // real files, so this also covers folders whose on-disk name predates
        // the current sanitization.
        $body = $this->store->bodyFromEml(substr($jsonPath, 0, -\strlen('.json')).'.eml');
        if (null === $body) {
            return $doc;
        }
        // An empty extraction is legitimate (image-only mail) and must still
        // replace a junk body — SyncService would have stored '' too.
        if ($body !== ($doc['body'] ?? '')) {
            $doc['body'] = $body;
            file_put_contents($jsonPath, json_encode($doc));
        }

        return $doc;
    }
}
