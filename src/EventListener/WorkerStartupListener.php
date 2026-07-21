<?php

namespace App\EventListener;

use App\Message\SyncMailboxMessage;
use App\Repository\MailboxRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Safety net for interrupted syncs: a sync that's killed mid-run (worker
 * restart, OOM, deploy) dies before its catch block, leaving the mailbox stuck
 * at "syncing" forever with no error. When the sync worker (re)starts, any
 * leftover "syncing" is stale — surface it and re-queue the sync so it resumes
 * on its own. (The crashed message would eventually be redelivered by the
 * doctrine transport, but only after its redeliver timeout — an hour by
 * default. Re-dispatching is immediate, and the per-folder UID cursor makes
 * the duplicate run a cheap no-op for whatever was already synced.)
 */
#[AsEventListener(event: WorkerStartedEvent::class)]
final class WorkerStartupListener
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MailboxRepository $mailboxes,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(WorkerStartedEvent $event): void
    {
        // Only the sync worker owns syncs. With separate search and sync workers,
        // a restart of the *search* worker must not flag the sync worker's
        // in-progress sync as interrupted.
        if (!\in_array('sync', $event->getWorker()->getMetadata()->getTransportNames(), true)) {
            return;
        }

        foreach ($this->mailboxes->findBy(['syncStatus' => 'syncing']) as $mb) {
            $mb->setSyncStatus('error')
                ->setLastError('Sync was interrupted before it finished (worker restarted); retrying automatically.');
            $this->em->flush();
            $this->bus->dispatch(new SyncMailboxMessage((string) $mb->getId()));
            $this->logger->info('Sync {label}: interrupted sync detected on worker startup, re-queued', ['label' => $mb->getLabel()]);
        }
    }
}
