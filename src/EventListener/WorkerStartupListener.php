<?php

namespace App\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;

/**
 * Safety net for interrupted syncs: a sync that's killed mid-run (worker
 * restart, OOM, deploy) dies before its catch block, leaving the mailbox stuck
 * at "syncing" forever with no error. When the worker (re)starts, nothing is
 * actively syncing (single worker), so any leftover "syncing" is stale — turn
 * it into a visible error so the user isn't staring at a frozen "indexing…".
 */
#[AsEventListener(event: WorkerStartedEvent::class)]
final class WorkerStartupListener
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function __invoke(WorkerStartedEvent $event): void
    {
        $this->em->createQuery(
            "UPDATE App\Entity\Mailbox m
             SET m.syncStatus = 'error',
                 m.lastError = 'Sync was interrupted before it finished (worker restarted). Click \"Sync now\" to retry.'
             WHERE m.syncStatus = 'syncing'"
        )->execute();
    }
}
