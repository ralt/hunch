<?php

namespace App\Service;

use Webklex\PHPIMAP\ClientManager;

/**
 * Tests IMAP settings (connect + login + list folders) without saving anything,
 * so the user knows a mailbox will work before adding it. Short timeout so the
 * request can't hang the web worker.
 */
final class ImapTester
{
    /**
     * @return array{ok:bool,message:string,folders?:list<string>}
     */
    public function test(string $host, int $port, string $username, string $password, string $security, bool $verifyCert): array
    {
        if ('' === $host || '' === $username) {
            return ['ok' => false, 'message' => 'Host and username are required.'];
        }

        $encryption = match ($security) {
            'none', '' => false,
            'ssl' => 'ssl',
            default => 'starttls',
        };

        try {
            $client = (new ClientManager())->make([
                'host' => $host,
                'port' => $port,
                'encryption' => $encryption,
                'validate_cert' => $verifyCert,
                'username' => $username,
                'password' => $password,
                'protocol' => 'imap',
                'timeout' => 12,
            ]);
            $client->connect();

            $names = [];
            foreach ($client->getFolders(false) as $folder) {
                $names[] = (string) $folder->path;
            }
            $client->disconnect();

            return [
                'ok' => true,
                'message' => \sprintf('Connected successfully — %d folder(s) found.', \count($names)),
                'folders' => \array_slice($names, 0, 25),
            ];
        } catch (\Throwable $e) {
            // RootCause keeps the library's outer message ("connection failed: …"),
            // so no hardcoded prefix — it would read "Connection failed: connection failed".
            return ['ok' => false, 'message' => ucfirst(RootCause::message($e))];
        }
    }
}
