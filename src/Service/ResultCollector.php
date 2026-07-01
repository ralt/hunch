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
                // Seen before: keep the higher relevance score so live re-ranking
                // reflects the best match across the agent's several searches.
                if ((float) ($hit['_rankingScore'] ?? 0) > (float) ($c['_rankingScore'] ?? 0)) {
                    $this->candidates[$n] = $hit;
                }

                return $n;
            }
        }
        $this->candidates[++$this->seq] = $hit;

        return $this->seq;
    }

    /**
     * @param array<int,array<string,mixed>> $results each {n, reason}
     *
     * @return array{presented:int,unknown:int[]} how many resolved, and any
     *                                             numbers that matched no candidate
     */
    public function present(array $results): array
    {
        $unknown = [];
        foreach ($results as $r) {
            $n = (int) ($r['n'] ?? 0);
            if (isset($this->candidates[$n])) {
                $this->presented[] = ['hit' => $this->candidates[$n], 'reason' => (string) ($r['reason'] ?? '')];
            } else {
                $unknown[] = $n;
            }
        }

        return ['presented' => \count($this->presented), 'unknown' => $unknown];
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

    /** @return array<int,array<string,mixed>> the full candidate registry (number => hit) */
    public function candidates(): array
    {
        return $this->candidates;
    }

    /** @return array<int,array<string,mixed>> candidate rows, ranked by relevance */
    public function rankedList(): array
    {
        return self::rank($this->candidates);
    }

    /**
     * Flatten the candidate registry into UI rows sorted by relevance score.
     *
     * @param array<int,array<string,mixed>> $candidates number => hit
     *
     * @return array<int,array<string,mixed>>
     */
    public static function rank(array $candidates): array
    {
        $list = [];
        foreach ($candidates as $n => $h) {
            $list[] = [
                'n' => (int) $n,
                'id' => (string) ($h['id'] ?? ''),
                'subject' => (string) ($h['subject'] ?? '(no subject)'),
                'from' => (string) ($h['from'] ?? ''),
                'date' => substr((string) ($h['dateISO'] ?? ''), 0, 10),
                'snippet' => (string) ($h['snippet'] ?? ''),
                'score' => round((float) ($h['_rankingScore'] ?? 0), 4),
            ];
        }
        usort($list, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $list;
    }

    /**
     * Restore a conversation's candidate registry so numbers stay stable across
     * turns. Continues numbering from the highest existing number.
     *
     * @param array<int,array<string,mixed>> $candidates number => hit
     */
    public function seed(array $candidates): void
    {
        $this->reset();
        foreach ($candidates as $n => $hit) {
            $n = (int) $n;
            $this->candidates[$n] = $hit;
            $this->seq = max($this->seq, $n);
        }
    }

    /** The worker reuses this singleton across jobs — clear it before each search. */
    public function reset(): void
    {
        $this->candidates = [];
        $this->presented = [];
        $this->seq = 0;
    }
}
