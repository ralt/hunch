<?php

namespace App\Tests\Service;

use App\Service\RootCause;
use PHPUnit\Framework\TestCase;

final class RootCauseTest extends TestCase
{
    public function testNoPreviousReturnsMessageAsIs(): void
    {
        $this->assertSame('boom', RootCause::message(new \RuntimeException('boom')));
    }

    public function testAppendsDeepestPreviousMessage(): void
    {
        // The webklex shape: generic wrapper hiding the actionable root cause.
        $root = new \ErrorException('getaddrinfo for imap.example.com failed');
        $mid = new \RuntimeException('stream error', 0, $root);
        $outer = new \RuntimeException('connection failed', 0, $mid);
        $this->assertSame(
            'connection failed: getaddrinfo for imap.example.com failed',
            RootCause::message($outer),
        );
    }

    public function testSkipsEmptyRootMessage(): void
    {
        $outer = new \RuntimeException('connection failed', 0, new \RuntimeException(''));
        $this->assertSame('connection failed', RootCause::message($outer));
    }

    public function testSkipsDuplicateRootMessage(): void
    {
        $outer = new \RuntimeException('same text', 0, new \RuntimeException('same text'));
        $this->assertSame('same text', RootCause::message($outer));
    }
}
