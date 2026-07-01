<?php

namespace App\Message;

/** Dispatched to sync one mailbox in the background (never in a web request). */
final class SyncMailboxMessage
{
    public function __construct(public readonly string $mailboxId)
    {
    }
}
