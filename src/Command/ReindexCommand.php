<?php

namespace App\Command;

use App\Service\Reindexer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('hunch:reindex', 'Rebuild the Meilisearch index from the on-disk emails (no IMAP)')]
final class ReindexCommand extends Command
{
    public function __construct(private readonly Reindexer $reindexer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('reparse', null, InputOption::VALUE_NONE, 'Re-extract bodies from the .eml files and rewrite the sidecars (after a fix to the extraction logic)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('hunch reindex');
        $n = $this->reindexer->reindexAll(static fn (string $m) => $io->writeln('  · '.$m), (bool) $input->getOption('reparse'));
        $io->success("$n document(s) reindexed.");

        return Command::SUCCESS;
    }
}
