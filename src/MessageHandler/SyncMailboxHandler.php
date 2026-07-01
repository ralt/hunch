<?php

namespace App\MessageHandler;

use App\Message\SyncMailboxMessage;
use App\Repository\MailboxRepository;
use App\Service\SyncService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final class SyncMailboxHandler
{
    public function __construct(
        private readonly MailboxRepository $mailboxes,
        private readonly SyncService $sync,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncMailboxMessage $message): void
    {
        $mb = Uuid::isValid($message->mailboxId)
            ? $this->mailboxes->find(Uuid::fromString($message->mailboxId))
            : null;

        if (null === $mb) {
            $this->logger->warning('Sync: mailbox {id} not found (deleted?)', ['id' => $message->mailboxId]);

            return;
        }

        $n = $this->sync->syncMailbox($mb, fn (string $s) => $this->logger->info('Sync {label}: {s}', ['label' => $mb->getLabel(), 's' => $s]));
        $this->logger->info('Sync {label}: {n} new message(s) indexed', ['label' => $mb->getLabel(), 'n' => $n]);
    }
}
