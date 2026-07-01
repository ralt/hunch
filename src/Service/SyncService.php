<?php

namespace App\Service;

use App\Entity\Mailbox;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webklex\PHPIMAP\ClientManager;

/**
 * Syncs one IMAP mailbox into the local filesystem and the Meilisearch index.
 *
 * Emails live on disk under var/maildir/<userId>/<mailboxId>/<folder>/:
 *   <uid>.eml   the raw message (open the original / reuse in mu4e)
 *   <uid>.json  the indexable document (lets `hunch:reindex` rebuild the
 *               search index with no IMAP round-trip and no MIME re-parsing)
 *
 * Postgres holds only the per-folder UID cursor (a setting on the Mailbox).
 */
final class SyncService
{
    public function __construct(
        private readonly MailIndex $index,
        private readonly Crypto $crypto,
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%/var/maildir')] private readonly string $maildir,
    ) {
    }

    /**
     * Sync a mailbox, recording status (syncing → ok/error) and any error on the
     * mailbox so the UI can show it. Re-throws so Messenger can retry.
     *
     * @param callable(string):void $progress
     *
     * @return int new messages indexed
     */
    public function syncMailbox(Mailbox $mb, callable $progress): int
    {
        $mb->setSyncStatus('syncing')->setLastError(null);
        $this->em->flush();
        try {
            $total = $this->doSync($mb, $progress);
            $mb->setSyncStatus('ok')->setLastSyncedAt(new \DateTimeImmutable())->setLastError(null);
            $this->em->flush();

            return $total;
        } catch (\Throwable $e) {
            $mb->setSyncStatus('error')->setLastError($e->getMessage());
            $this->em->flush();
            throw $e;
        }
    }

    /** @param callable(string):void $progress */
    private function doSync(Mailbox $mb, callable $progress): int
    {
        $password = $this->crypto->decrypt($mb->getImapPasswordEnc());
        $encryption = match ($mb->getSecurity()) {
            'none', '' => false,
            'ssl' => 'ssl',
            default => 'starttls',
        };

        $client = (new ClientManager())->make([
            'host' => $mb->getImapHost(),
            'port' => $mb->getImapPort(),
            'encryption' => $encryption,
            'validate_cert' => $mb->isVerifyCert(),
            'username' => $mb->getImapUsername(),
            'password' => $password,
            'protocol' => 'imap',
            // Without this, a stalled IMAP read blocks the single worker forever.
            'timeout' => 30,
        ]);
        $client->connect();

        $userId = (string) $mb->getOwner()->getId();
        $mbId = (string) $mb->getId();
        $total = 0;

        $perPage = 50;
        foreach ($mb->getFolders() as $folder) {
            $last = $mb->lastUid($folder);
            $dir = \sprintf('%s/%s/%s/%s', $this->maildir, $userId, $mbId, $this->sanitize($folder));
            @mkdir($dir, 0o775, true);

            // webklex can't narrow server-side (its whereUid() quotes ranges into
            // invalid sequence sets, and it sends an invalid SEARCH without
            // ->all()), so we page through with a client-side cutoff.
            $folderNew = 0;
            $page = 1;

            if ($last <= 0) {
                // First/full sync: ascending, persisting the cursor after EVERY
                // batch so a large initial sync resumes instead of restarting.
                do {
                    $messages = $client->getFolder($folder)->query()
                        ->all()->setFetchOrder('asc')->limit($perPage, $page)->get();
                    $count = $messages->count();

                    $batch = [];
                    $batchMax = $mb->lastUid($folder);
                    foreach ($messages as $message) {
                        $uid = (int) $message->getUid();
                        if ($uid <= $mb->lastUid($folder)) {
                            continue; // already synced — skipped before its body is fetched
                        }
                        [$doc, $raw] = $this->toDocument($userId, $mbId, $folder, $uid, $message);
                        $this->writeDoc($dir, $uid, $doc, $raw);
                        $batch[] = $doc;
                        $batchMax = max($batchMax, $uid);
                    }
                    if ($batch) {
                        $this->index->add($batch);
                        $total += \count($batch);
                        $folderNew += \count($batch);
                        $mb->setLastUid($folder, $batchMax);
                        $this->em->flush();
                        $progress(\sprintf('%s: indexed %d (uid %d)', $folder, $folderNew, $batchMax));
                    }
                    unset($messages, $batch);
                    gc_collect_cycles();
                    ++$page;
                } while ($count === $perPage);
            } else {
                // Incremental sync: descending, so the newest messages come first
                // and we STOP as soon as we reach an already-synced UID — instead
                // of re-paging the whole mailbox to find a handful of new mail.
                // Re-indexing is idempotent (docs keyed by uid), so we only need
                // to advance the cursor once the run finishes.
                $newMax = $last;
                $reachedSynced = false;
                do {
                    $messages = $client->getFolder($folder)->query()
                        ->all()->setFetchOrder('desc')->limit($perPage, $page)->get();
                    $count = $messages->count();

                    $batch = [];
                    foreach ($messages as $message) {
                        $uid = (int) $message->getUid();
                        if ($uid <= $last) {
                            $reachedSynced = true; // desc order: everything after is older too
                            continue;
                        }
                        [$doc, $raw] = $this->toDocument($userId, $mbId, $folder, $uid, $message);
                        $this->writeDoc($dir, $uid, $doc, $raw);
                        $batch[] = $doc;
                        $newMax = max($newMax, $uid);
                    }
                    if ($batch) {
                        $this->index->add($batch);
                        $total += \count($batch);
                        $folderNew += \count($batch);
                        $progress(\sprintf('%s: indexed %d (uid %d)', $folder, $folderNew, $newMax));
                    }
                    unset($messages, $batch);
                    gc_collect_cycles();
                    ++$page;
                } while ($count === $perPage && !$reachedSynced);

                if ($newMax > $last) {
                    $mb->setLastUid($folder, $newMax);
                    $this->em->flush();
                }
            }

            $progress(\sprintf('%s: done (%d new)', $folder, $folderNew));
        }

        return $total;
    }

    /**
     * @return array{0:array<string,mixed>,1:?string} [doc, rawEml]
     */
    private function toDocument(string $userId, string $mbId, string $folder, int $uid, object $message): array
    {
        // webklex returns address headers as an Attribute (not a plain array),
        // so the old is_array() check always missed and from/to came out empty.
        $fromAddr = $this->firstAddress($message->getFrom());
        $fromName = $fromAddr ? $this->decodeHeader((string) ($fromAddr->personal ?? '')) : '';
        $mail = $fromAddr ? (string) ($fromAddr->mail ?? '') : '';
        $fromStr = trim(($fromName ? $fromName.' ' : '').($mail ? '<'.$mail.'>' : ''));

        $to = [];
        foreach ($this->addresses($message->getTo()) as $a) {
            if (!empty($a->mail)) {
                $to[] = (string) $a->mail;
            }
        }

        $ts = ($d = $message->getDate()) ? (strtotime((string) $d) ?: 0) : 0;

        $body = (string) $message->getTextBody();
        if ('' === $body) {
            $body = trim(html_entity_decode(strip_tags((string) $message->getHTMLBody())));
        }

        $doc = [
            'id' => sha1($mbId.':'.$folder.':'.$uid),
            'userId' => $userId,
            'mailboxId' => $mbId,
            'folder' => $folder,
            'uid' => $uid,
            'messageId' => (string) $message->getMessageId(),
            'dateUnix' => $ts,
            'dateISO' => $ts ? gmdate('c', $ts) : '',
            'from' => $fromStr,
            'fromName' => $fromName,
            'to' => implode(', ', $to),
            'subject' => $this->decodeHeader((string) $message->getSubject()),
            'body' => $body,
        ];

        // Store a faithful RFC822 message (headers + body), not just the body
        // part — so the .eml is openable elsewhere (e.g. mu4e).
        $raw = null;
        if (method_exists($message, 'getHeader') && ($h = $message->getHeader()) && isset($h->raw)) {
            $raw = rtrim((string) $h->raw)."\r\n\r\n".(method_exists($message, 'getRawBody') ? (string) $message->getRawBody() : '');
        } elseif (method_exists($message, 'getRawBody')) {
            $raw = (string) $message->getRawBody();
        }

        // Email bodies/headers aren't always clean UTF-8 (mis-declared charsets,
        // truncated multibyte sequences). Force every string field to valid UTF-8
        // so json_encode (sidecar) and Meilisearch's payload encoder never choke.
        $doc = array_map(fn ($v) => \is_string($v) ? $this->utf8($v) : $v, $doc);

        return [$doc, $raw];
    }

    /** Write the raw .eml (if available) and the indexable .json sidecar for one message. */
    private function writeDoc(string $dir, int $uid, array $doc, ?string $raw): void
    {
        if (null !== $raw) {
            @file_put_contents("$dir/$uid.eml", $raw);
        }
        file_put_contents("$dir/$uid.json", json_encode($doc));
    }

    /** Force valid UTF-8, substituting any stray/invalid bytes. */
    private function utf8(string $s): string
    {
        if ('' === $s || mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }

        return mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    }

    /** Normalize webklex's Attribute|array|null address header to a list of Address objects. */
    private function addresses(mixed $v): array
    {
        if (\is_array($v)) {
            return $v;
        }
        if (\is_object($v) && method_exists($v, 'all')) {
            return (array) $v->all();
        }

        return [];
    }

    private function firstAddress(mixed $v): ?object
    {
        $first = $this->addresses($v)[0] ?? null;

        return \is_object($first) ? $first : null;
    }

    /** Decode MIME encoded-words (=?utf-8?Q?…?=) that occasionally slip through. */
    private function decodeHeader(string $s): string
    {
        if ('' === $s || !str_contains($s, '=?')) {
            return $s;
        }
        $decoded = @mb_decode_mimeheader($s);

        return false === $decoded || '' === $decoded ? $s : $decoded;
    }

    private function sanitize(string $folder): string
    {
        return str_replace(['/', '\\', ' '], ['.', '.', '_'], $folder);
    }
}
