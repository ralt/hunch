<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Encrypts mailbox IMAP passwords at rest with libsodium. The key is derived
 * from APP_SECRET, so no extra secret to manage for a showcase. (For stronger
 * separation, derive from a dedicated MAILBOX_SECRET instead.)
 */
final class Crypto
{
    private string $key;

    public function __construct(#[Autowire('%kernel.secret%')] string $secret)
    {
        $this->key = sodium_crypto_generichash($secret, '', \SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce.sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    public function decrypt(string $enc): string
    {
        $raw = base64_decode($enc, true);
        if (false === $raw || \strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Malformed ciphertext.');
        }
        $nonce = substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key);
        if (false === $plain) {
            throw new \RuntimeException('Decryption failed (key changed?).');
        }

        return $plain;
    }
}
