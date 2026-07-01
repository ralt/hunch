<?php

namespace App\Tests\Service;

use App\Service\ResultCollector;
use PHPUnit\Framework\TestCase;

final class ResultCollectorTest extends TestCase
{
    public function testRegisterAssignsSequentialNumbers(): void
    {
        $c = new ResultCollector();
        $this->assertSame(1, $c->registerCandidate(['id' => 'a']));
        $this->assertSame(2, $c->registerCandidate(['id' => 'b']));
    }

    public function testRegisterDedupesById(): void
    {
        $c = new ResultCollector();
        $n1 = $c->registerCandidate(['id' => 'a']);
        $n2 = $c->registerCandidate(['id' => 'a']);
        $this->assertSame($n1, $n2);
        $this->assertCount(1, $c->candidates());
    }

    public function testRegisterKeepsHigherScore(): void
    {
        $c = new ResultCollector();
        $c->registerCandidate(['id' => 'a', '_rankingScore' => 0.4]);
        $c->registerCandidate(['id' => 'a', '_rankingScore' => 0.9]);
        $ranked = $c->rankedList();
        $this->assertSame(0.9, $ranked[0]['score']);
    }

    public function testSeedContinuesNumbering(): void
    {
        $c = new ResultCollector();
        $c->seed([7 => ['id' => 'x'], 19 => ['id' => 'y']]);
        // A stale reference (#7) still resolves after seeding.
        $this->assertSame(7, $c->registerCandidate(['id' => 'x']));
        // New candidates continue past the highest seeded number.
        $this->assertSame(20, $c->registerCandidate(['id' => 'z']));
    }

    public function testPresentResolvesKnownAndReportsUnknown(): void
    {
        $c = new ResultCollector();
        $c->registerCandidate(['id' => 'a']); // #1
        $r = $c->present([['n' => 1, 'reason' => 'match'], ['n' => 99, 'reason' => 'nope']]);
        $this->assertSame(1, $r['presented']);
        $this->assertSame([99], $r['unknown']);
        $this->assertCount(1, $c->results());
    }

    public function testRankSortsByScoreDescending(): void
    {
        $ranked = ResultCollector::rank([
            1 => ['id' => 'a', 'subject' => 'A', '_rankingScore' => 0.5],
            2 => ['id' => 'b', 'subject' => 'B', '_rankingScore' => 0.9],
            3 => ['id' => 'c', 'subject' => 'C', '_rankingScore' => 0.7],
        ]);
        $this->assertSame(['b', 'c', 'a'], array_column($ranked, 'id'));
        $this->assertSame(2, $ranked[0]['n']); // stable candidate number preserved
    }
}
