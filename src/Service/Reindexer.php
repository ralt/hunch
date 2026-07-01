<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;

/**
 * Rebuilds the Meilisearch index from the on-disk JSON sidecars — no IMAP, no
 * MIME parsing. Use after changing the embedding model or resetting the index.
 */
final class Reindexer
{
    public function __construct(
        private readonly MailIndex $index,
        #[Autowire('%kernel.project_dir%/var/maildir')] private readonly string $maildir,
    ) {
    }

    /** @param callable(string):void $progress @return int documents reindexed */
    public function reindexAll(callable $progress): int
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
}
