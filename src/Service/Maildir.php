<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webklex\PHPIMAP\Message;

/**
 * Path layout and read access for the on-disk mail store
 * (var/maildir/<userId>/<mailboxId>/<folder>/<uid>.{eml,json}).
 * Meilisearch is only the index for *finding* mail; reading one message goes
 * to the .eml on disk — the source of truth.
 */
final class Maildir
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/var/maildir')] private readonly string $root,
    ) {
    }

    public function folderDir(string $userId, string $mailboxId, string $folder): string
    {
        return \sprintf('%s/%s/%s/%s', $this->root, $userId, $mailboxId, self::sanitizeFolder($folder));
    }

    /**
     * Extract the readable body from a stored .eml. Null when the file is
     * missing (messages synced before .eml storage) or unparsable.
     */
    public function readBody(string $userId, string $mailboxId, string $folder, int $uid): ?string
    {
        return $this->bodyFromEml($this->folderDir($userId, $mailboxId, $folder).'/'.$uid.'.eml');
    }

    public function bodyFromEml(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        try {
            $body = MailText::extract(Message::fromString((string) file_get_contents($path)));
        } catch (\Throwable) {
            return null;
        }

        // Same UTF-8 forcing as everywhere a body leaves this app: mis-declared
        // charsets must not break JSON encoding downstream.
        if ('' !== $body && !mb_check_encoding($body, 'UTF-8')) {
            $body = mb_convert_encoding($body, 'UTF-8', 'UTF-8');
        }

        return $body;
    }

    /** Folder names may contain separators; flatten them for the filesystem. */
    public static function sanitizeFolder(string $folder): string
    {
        return str_replace(['/', '\\', ' '], ['.', '.', '_'], $folder);
    }
}
