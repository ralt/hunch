<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Guards user-supplied hosts/URLs (Ollama base URL, IMAP host) against SSRF.
 *
 * Link-local addresses (169.254.0.0/16, fe80::/10 — i.e. cloud metadata) and the
 * unspecified address are ALWAYS blocked: nothing legitimate lives there. Full
 * private/loopback blocking is opt-in via HUNCH_STRICT_SSRF, because the common
 * self-hosted setup deliberately points at localhost (Ollama, Proton Bridge).
 * Multi-tenant/hosted deployments should set HUNCH_STRICT_SSRF=1.
 */
final class SsrfGuard
{
    public function __construct(
        #[Autowire('%env(bool:HUNCH_STRICT_SSRF)%')]
        private readonly bool $strict = false,
    ) {
    }

    /** @throws \RuntimeException if the URL's host is a disallowed target */
    public function assertAllowedUrl(string $url): void
    {
        $parts = parse_url(trim($url));
        if (false === $parts || empty($parts['host'])) {
            throw new \RuntimeException('Invalid URL.');
        }
        if (isset($parts['scheme']) && !\in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new \RuntimeException('Only http(s) URLs are allowed.');
        }
        $this->assertAllowedHost($parts['host']);
    }

    /** @throws \RuntimeException if the host resolves to a disallowed address */
    public function assertAllowedHost(string $host): void
    {
        $host = trim(trim(strtolower($host)), '[]');
        if ('' === $host) {
            throw new \RuntimeException('Empty host.');
        }

        foreach ($this->resolve($host) as $ip) {
            if ($this->isAlwaysBlocked($ip)) {
                throw new \RuntimeException(\sprintf('Host "%s" resolves to a blocked address (%s).', $host, $ip));
            }
            if ($this->strict
                && false === filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE)) {
                throw new \RuntimeException(\sprintf('Host "%s" resolves to a private/reserved address (%s); only public hosts are allowed here.', $host, $ip));
            }
        }
    }

    /** Link-local (incl. 169.254.169.254 metadata) and unspecified — never legitimate. */
    private function isAlwaysBlocked(string $ip): bool
    {
        if (str_starts_with($ip, '169.254.') || '0.0.0.0' === $ip) {
            return true;
        }
        $packed = @inet_pton($ip);
        if (false !== $packed && 16 === \strlen($packed)) {
            if (str_repeat("\0", 16) === $packed) {
                return true; // :: (unspecified)
            }
            // fe80::/10 (IPv6 link-local)
            if (0xFE === \ord($packed[0]) && 0x80 === (\ord($packed[1]) & 0xC0)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string> resolved IPs (the literal itself if $host is an IP)
     *
     * @throws \RuntimeException if the host cannot be resolved
     */
    private function resolve(string $host): array
    {
        if (false !== filter_var($host, \FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = @gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, \DNS_AAAA) ?: [] as $record) {
            if (!empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }
        if (!$ips) {
            throw new \RuntimeException(\sprintf('Could not resolve host "%s".', $host));
        }

        return array_values(array_unique($ips));
    }
}
