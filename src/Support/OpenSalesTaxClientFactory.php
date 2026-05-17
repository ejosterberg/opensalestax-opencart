<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\OpenCart\Support;

use GuzzleHttp\Client as GuzzleClient;
use InvalidArgumentException;
use OpenSalesTax\Client;
use OpenSalesTax\OpenCart\Exceptions\ConfigurationException;

/**
 * Build a configured SDK `OpenSalesTax\Client` from a `ConfigBag`.
 *
 * Returns `null` when:
 *  - the bag's `baseUrl` is empty (extension is inert; the caller yields to
 *    OpenCart)
 *  - URL validation fails AND `failHard` is false (we log + return null; the
 *    fail-hard path rethrows as `ConfigurationException`)
 *
 * Returns a built `Client` otherwise, with TLS verify driven by the
 * `tlsVerify` flag and timeout pulled from `timeoutSeconds`.
 */
class OpenSalesTaxClientFactory
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ?UrlValidator $validator = null,
    ) {
    }

    public function make(ConfigBag $config): ?Client
    {
        if ($config->baseUrl === '') {
            return null;
        }

        $validator = $this->validator ?? new UrlValidator($config->allowPrivateNets);

        try {
            [$host, $pinnedIp] = $validator->validateAndResolve($config->baseUrl);
        } catch (InvalidArgumentException $e) {
            $this->logger->warning('opensalestax: base URL rejected by validator', [
                'reason' => $e->getMessage(),
            ]);
            if ($config->failHard) {
                throw new ConfigurationException($e->getMessage(), 0, $e);
            }
            return null;
        }

        $guzzle = new GuzzleClient($this->buildHttpOptions($config, $host, $pinnedIp));

        return new Client(
            baseUrl: $config->baseUrl,
            apiKey: $config->apiKey !== '' ? $config->apiKey : null,
            timeoutSeconds: $config->timeoutSeconds,
            httpClient: $guzzle,
        );
    }

    /**
     * Build the Guzzle config array, including the cURL `RESOLVE` directive
     * that pins `host:port` to the IP captured at validation time.
     *
     * Why pin: defeats DNS rebinding. The validator may have accepted
     * `https://ost.example.com` resolving to `203.0.113.42`; without
     * pinning, a malicious DNS responder could resolve the same host to
     * `127.0.0.1` at request time. cURL keeps the request URL (TLS SNI +
     * cert validation) on the original hostname but opens the TCP socket
     * to our pinned IP.
     *
     * Falls back to an un-pinned client when the cURL extension is
     * unavailable (e.g. PHP built with `--disable-curl`) so the connector
     * still works in those installs. Pinning is defense-in-depth on top of
     * the save-time SSRF check; absence of cURL doesn't reopen the SSRF
     * primary defense.
     *
     * @return array<string, mixed>
     */
    private function buildHttpOptions(ConfigBag $config, string $host, string $pinnedIp): array
    {
        $options = [
            'timeout' => $config->timeoutSeconds,
            'verify'  => $config->tlsVerify,
        ];

        if (!extension_loaded('curl') || !defined('CURLOPT_RESOLVE')) {
            $this->logger->warning('opensalestax: cURL unavailable; IP-pin defense disabled', [
                'host' => $host,
            ]);
            return $options;
        }

        $options['curl'] = [
            CURLOPT_RESOLVE => [self::buildResolveDirective($config->baseUrl, $host, $pinnedIp)],
        ];
        return $options;
    }

    /**
     * Build a cURL `CURLOPT_RESOLVE` directive string of the form
     * `host:port:ip`. Exposed `public static` so tests can assert on the
     * exact pin without standing up a full Guzzle client.
     */
    public static function buildResolveDirective(string $url, string $host, string $pinnedIp): string
    {
        return sprintf('%s:%d:%s', $host, self::portForUrl($url), $pinnedIp);
    }

    /**
     * Choose the cURL `RESOLVE` port for the given URL: an explicit `:port`
     * in the URL wins; otherwise 443 for https, 80 for http.
     */
    private static function portForUrl(string $url): int
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return 443;
        }
        if (isset($parts['port'])) {
            return $parts['port'];
        }
        $scheme = $parts['scheme'] ?? 'https';
        return $scheme === 'http' ? 80 : 443;
    }
}
