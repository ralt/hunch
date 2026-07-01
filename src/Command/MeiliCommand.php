<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Convenience for local dev: download (if needed) the official Meilisearch
 * binary for this platform and run it in the foreground, so you don't have to
 * install or manage a daemon. Runs keyless (dev mode) on 127.0.0.1:7700.
 *
 * On Upsun this command isn't used — see .upsun/config.yaml, where Meilisearch
 * runs as a colocated background process on a writable mount.
 */
#[AsCommand('hunch:meili', 'Download and run a local Meilisearch (foreground)')]
final class MeiliCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $binDir = $this->projectDir.'/var/bin';
        $dataDir = $this->projectDir.'/var/meili';
        @mkdir($binDir, 0o755, true);
        @mkdir($dataDir, 0o755, true);
        $binary = $binDir.'/meilisearch';

        if (!is_file($binary)) {
            try {
                $this->download($io, $binary);
            } catch (\Throwable $e) {
                $io->error('Could not download Meilisearch: '.$e->getMessage());
                $io->note('Install it yourself (e.g. `brew install meilisearch`) or use `docker compose up -d meili`.');

                return Command::FAILURE;
            }
        }

        $io->success('Starting Meilisearch on http://127.0.0.1:7700 (Ctrl-C to stop)');
        $process = new Process([
            $binary,
            '--db-path', $dataDir,
            '--http-addr', '127.0.0.1:7700',
            '--no-analytics',
        ]);
        $process->setTimeout(null);
        $process->run(static fn ($type, $buffer) => $output->write($buffer));

        return $process->getExitCode() ?? Command::SUCCESS;
    }

    private function download(SymfonyStyle $io, string $dest): void
    {
        $asset = $this->assetName();
        $io->writeln('Resolving latest Meilisearch release...');
        $ctx = stream_context_create(['http' => ['header' => "User-Agent: hunch\r\n"]]);
        $release = json_decode((string) file_get_contents(
            'https://api.github.com/repos/meilisearch/meilisearch/releases/latest', false, $ctx
        ), true);
        $url = null;
        foreach ($release['assets'] ?? [] as $a) {
            if (($a['name'] ?? '') === $asset) {
                $url = $a['browser_download_url'];
                break;
            }
        }
        if (!$url) {
            throw new \RuntimeException("no asset {$asset} in release ".($release['tag_name'] ?? '?'));
        }
        $io->writeln("Downloading {$asset} ({$release['tag_name']})...");
        copy($url, $dest);
        chmod($dest, 0o755);
    }

    private function assetName(): string
    {
        $arch = php_uname('m');
        $arm = \in_array($arch, ['arm64', 'aarch64'], true);

        return match (\PHP_OS_FAMILY) {
            'Darwin' => $arm ? 'meilisearch-macos-apple-silicon' : 'meilisearch-macos-amd64',
            'Windows' => 'meilisearch-windows-amd64.exe',
            default => $arm ? 'meilisearch-linux-aarch64' : 'meilisearch-linux-amd64',
        };
    }
}
