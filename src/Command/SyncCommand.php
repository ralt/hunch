<?php

namespace App\Command;

use App\Repository\MailboxRepository;
use App\Service\SyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('hunch:sync', 'Sync all configured mailboxes over IMAP into the filesystem + index')]
final class SyncCommand extends Command
{
    public function __construct(
        private readonly MailboxRepository $mailboxes,
        private readonly SyncService $sync,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $boxes = $this->mailboxes->findAll();
        if (!$boxes) {
            $io->warning('No mailboxes configured. Add one at /mailboxes first.');

            return Command::SUCCESS;
        }
        $grand = 0;
        foreach ($boxes as $mb) {
            $io->section(\sprintf('%s (%s)', $mb->getLabel(), $mb->getOwner()->getEmail()));
            try {
                $grand += $this->sync->syncMailbox($mb, static fn (string $m) => $io->writeln('  · '.$m));
            } catch (\Throwable $e) {
                $io->error($mb->getLabel().': '.$e->getMessage());
            }
        }
        $io->success(\sprintf('%d new message(s) indexed across %d mailbox(es).', $grand, \count($boxes)));

        return Command::SUCCESS;
    }
}
