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

            // webklex can only fetch by page (its whereUid()/whereUidIn() emit an
            // invalid SEARCH — "BAD Could not parse command"), so we page in
            // ascending UID order. To avoid re-fetching the whole already-synced
            // range every run, we first do a cheap SEARCH (UIDs only, no bodies)
            // to learn how many messages precede the cursor, and START paging on
            // the page where new mail begins (one page early, as a safety margin
            // against ordering quirks). We index each page's new messages and
            // persist the cursor per batch, so progress is durable: an interrupted
            // sync resumes near where it stopped instead of restarting.
            //
            // ->softFail(): skip a message that errors during fetch rather than
            // aborting the batch — notably webklex's "flag could not be removed"
            // when it reconciles \Seen even in read-only PEEK mode.
            $folderNew = 0;
            $allUids = array_map('intval', $client->getFolder($folder)->query()->all()->search()->toArray());
            $oldCount = \count(array_filter($allUids, static fn (int $u): bool => $u <= $last));
            $page = max(1, intdiv($oldCount, $perPage)); // 1 page before the new mail

            if ($oldCount < \count($allUids)) {
                do {
                    $messages = $client->getFolder($folder)->query()->softFail()
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
                        $mb->setLastUid($folder, $batchMax); // ascending high-water; durable
                        $this->em->flush();
                        $progress(\sprintf('%s: indexed %d (uid %d)', $folder, $folderNew, $batchMax));
                    }
                    unset($messages, $batch);
                    gc_collect_cycles();
                    ++$page;
                } while ($count === $perPage);
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
