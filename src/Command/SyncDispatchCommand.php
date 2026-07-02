<?php

namespace App\Command;

use App\Message\SyncMailboxMessage;
use App\Repository\MailboxRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand('hunch:sync-dispatch', 'Queue a sync for every mailbox (processed by the sync worker)')]
final class SyncDispatchCommand extends Command
{
    public function __construct(
        private readonly MailboxRepository $mailboxes,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->mailboxes->findAll() as $mb) {
            $this->bus->dispatch(new SyncMailboxMessage((string) $mb->getId()));
            $output->writeln(\sprintf('Queued sync for "%s"', $mb->getLabel()));
        }

        return Command::SUCCESS;
    }
}
