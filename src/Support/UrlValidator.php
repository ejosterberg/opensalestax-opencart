<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace OpenSalesTax\OpenCart\Support;

use InvalidArgumentException;

/**
 * SSRF-defense URL validator for the OpenSalesTax engine base URL.
 *
 * Rules (in order):
 *  1. Empty input — reject.
 *  2. Parse failure or missing scheme/host — reject.
 *  3. Scheme must be `http` or `https` — reject otherwise.
 *  4. When `allowPrivateNets` is FALSE (default), every resolved IP must be
 *     public — reject if any resolved address falls in:
 *       - RFC1918    (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16)
 *       - loopback   (127.0.0.0/8, ::1)
 *       - link-local (169.254.0.0/16, fe80::/10) — including the AWS / GCP
 *                    metadata endpoint at 169.254.169.254
 *       - CGNAT      (100.64.0.0/10, RFC 6598)
 *       - multicast  (224.0.0.0/4, ff00::/8)
 *
 * Default-strict because the dominant attack class against admin-controlled
 * URLs is SSRF: an attacker who can edit OpenCart's settings table (a
 * privilege escalation from any admin-side vuln) can otherwise direct the
 * extension at internal services (Redis, intranet apps, cloud metadata).
 *
 * Merchants who legitimately self-host OST on the same LAN as OpenCart opt
 * in via the "Allow private network engines" admin toggle.
 */
class UrlValidator
{
    /** @var callable(string):string[] */
    private $hostResolver;

    /**
     * @param callable(string):string[]|null $hostResolver Function returning the
     *     list of resolved IPv4/IPv6 addresses for a hostname, or [] on
     *     failure. The default uses gethostbynamel; tests inject a mock.
     */
    public function __construct(
        private readonly bool $allowPrivateNets,
        ?callable $hostResolver = null,
    ) {
        $this->hostResolver = $hostResolver ?? static function (string $host): array {
            if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
                return [$host];
            }
            $list = gethostbynamel($host);
            return $list === false ? [] : $list;
        };
    }

    /**
     * @throws InvalidArgumentException With a human-readable rejection reason.
     */
    public function validate(string $url): void
    {
        if ($url === '') {
            throw new InvalidArgumentException('The OpenSalesTax engine base URL is empty.');
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme']) || !isset($parts['host'])) {
            throw new InvalidArgumentException(
                'The OpenSalesTax engine base URL must be fully-qualified (e.g. https://ost.example.com).',
            );
        }

        if (!in_array($parts['scheme'], ['http', 'https'], true)) {
            throw new InvalidArgumentException(
                'The OpenSalesTax engine base URL must use http or https.',
            );
        }

        if ($this->allowPrivateNets) {
            return;
        }

        $this->rejectPrivateHost((string) $parts['host']);
    }

    /**
     * @throws InvalidArgumentException When the host resolves to any
     *     private / reserved IP range, or fails to resolve at all.
     */
    private function rejectPrivateHost(string $host): void
    {
        $resolver = $this->hostResolver;
        $ips = $resolver($host);

        if ($ips === []) {
            throw new InvalidArgumentException(
                'The OpenSalesTax engine base URL host could not be resolved.',
            );
        }

        foreach ($ips as $ip) {
            if (!self::isPublic($ip)) {
                throw new InvalidArgumentException(
                    'The OpenSalesTax engine base URL resolves to a private / reserved IP. ' .
                    'Enable "Allow private network engines" in the admin to permit private-LAN engines.',
                );
            }
        }
    }

    private static function isPublic(string $ip): bool
    {
        $valid = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );
        // CGNAT (100.64.0.0/10, RFC 6598) is not consistently flagged by NO_RES_RANGE;
        // PHP also doesn't reliably reject IPv4 multicast (224.0.0.0/4) under
        // NO_RES_RANGE across versions. Check both explicitly.
        return $valid !== false && !self::isCgnat($ip) && !self::isIpv4Multicast($ip);
    }

    private static function isCgnat(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        $long = ip2long($ip);
        if ($long === false) {
            return false;
        }
        // 100.64.0.0/10 → 100.64.0.0 (0x64400000) .. 100.127.255.255 (0x647FFFFF)
        return $long >= 0x64400000 && $long <= 0x647FFFFF;
    }

    private static function isIpv4Multicast(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        $long = ip2long($ip);
        if ($long === false) {
            return false;
        }
        // 224.0.0.0/4 → 224.0.0.0 (0xE0000000) .. 239.255.255.255 (0xEFFFFFFF)
        return $long >= 0xE0000000 && $long <= 0xEFFFFFFF;
    }
}
