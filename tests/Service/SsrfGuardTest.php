<?php

namespace App\Tests\Service;

use App\Service\SsrfGuard;
use PHPUnit\Framework\TestCase;

final class SsrfGuardTest extends TestCase
{
    public function testLinkLocalMetadataAlwaysBlocked(): void
    {
        $this->expectException(\RuntimeException::class);
        (new SsrfGuard(false))->assertAllowedHost('169.254.169.254');
    }

    public function testUnspecifiedAddressBlocked(): void
    {
        $this->expectException(\RuntimeException::class);
        (new SsrfGuard(false))->assertAllowedHost('0.0.0.0');
    }

    public function testLoopbackAllowedByDefault(): void
    {
        // Self-hosted setups use localhost (Ollama, Proton Bridge) — must work.
        $this->assertNull((new SsrfGuard(false))->assertAllowedHost('127.0.0.1'));
    }

    public function testPrivateRangeAllowedByDefault(): void
    {
        $this->assertNull((new SsrfGuard(false))->assertAllowedHost('10.0.0.5'));
    }

    public function testPublicAddressAllowed(): void
    {
        $this->assertNull((new SsrfGuard(false))->assertAllowedHost('8.8.8.8'));
    }

    public function testNonHttpSchemeRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        (new SsrfGuard(false))->assertAllowedUrl('ftp://example.com/x');
    }

    public function testMetadataUrlBlocked(): void
    {
        $this->expectException(\RuntimeException::class);
        (new SsrfGuard(false))->assertAllowedUrl('http://169.254.169.254/latest/meta-data');
    }

    public function testHttpLocalhostUrlAllowedByDefault(): void
    {
        $this->assertNull((new SsrfGuard(false))->assertAllowedUrl('http://localhost:11434'));
    }

    public function testStrictModeBlocksLoopback(): void
    {
        $this->expectException(\RuntimeException::class);
        (new SsrfGuard(true))->assertAllowedHost('127.0.0.1');
    }

    public function testStrictModeBlocksPrivateRange(): void
    {
        $this->expectException(\RuntimeException::class);
        (new SsrfGuard(true))->assertAllowedHost('10.0.0.5');
    }

    public function testStrictModeAllowsPublic(): void
    {
        $this->assertNull((new SsrfGuard(true))->assertAllowedHost('8.8.8.8'));
    }
}
