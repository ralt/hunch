<?php

namespace App\Tests\Service;

use App\Service\Crypto;
use PHPUnit\Framework\TestCase;

final class CryptoTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $crypto = new Crypto('a-test-secret');
        $enc = $crypto->encrypt('hunter2');
        $this->assertNotSame('hunter2', $enc);
        $this->assertSame('hunter2', $crypto->decrypt($enc));
    }

    public function testCiphertextIsNonDeterministic(): void
    {
        $crypto = new Crypto('a-test-secret');
        $this->assertNotSame($crypto->encrypt('same'), $crypto->encrypt('same'));
    }

    public function testMalformedCiphertextThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Crypto('a-test-secret'))->decrypt('not-valid-base64-ciphertext!!');
    }

    public function testWrongKeyCannotDecrypt(): void
    {
        $enc = (new Crypto('secret-one'))->encrypt('data');
        $this->expectException(\RuntimeException::class);
        (new Crypto('secret-two'))->decrypt($enc);
    }
}
