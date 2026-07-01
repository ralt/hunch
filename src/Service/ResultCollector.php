<?php

namespace App\Service;

/**
 * Bridges the agent's tool calls back to the controller/command. The
 * search_emails tool registers candidates here (assigning each a stable number
 * the model refers to); present_results records the final picks. Shared within
 * a request, so whatever the agent decided is readable after $agent->call().
 */
final class ResultCollector
{
    /** @var array<int,array<string,mixed>> number => hit */
    private array $candidates = [];
    private int $seq = 0;

    /** @var array<int,array{hit:array<string,mixed>,reason:string}> */
    private array $presented = [];

    /** @param array<string,mixed> $hit @return int the candidate number */
    public function registerCandidate(array $hit): int
    {
        foreach ($this->candidates as $n => $c) {
            if (($c['id'] ?? null) === ($hit['id'] ?? null)) {
                return $n;
            }
        }
        $this->candidates[++$this->seq] = $hit;

        return $this->seq;
    }

    /** @param array<int,array<string,mixed>> $results each {n, reason} */
    public function present(array $results): void
    {
        foreach ($results as $r) {
            $n = (int) ($r['n'] ?? 0);
            if (isset($this->candidates[$n])) {
                $this->presented[] = ['hit' => $this->candidates[$n], 'reason' => (string) ($r['reason'] ?? '')];
            }
        }
    }

    public function hasResults(): bool
    {
        return [] !== $this->presented;
    }

    /** @return array<int,array{hit:array<string,mixed>,reason:string}> */
    public function results(): array
    {
        return $this->presented;
    }

    /** The worker reuses this singleton across jobs — clear it before each search. */
    public function reset(): void
    {
        $this->candidates = [];
        $this->presented = [];
        $this->seq = 0;
    }
}
