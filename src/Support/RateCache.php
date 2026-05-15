<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace OpenSalesTax\OpenCart\Support;

use OpenSalesTax\Responses\CalculateResponse;

/**
 * Cache-backed wrapper around the OST engine's calculate response.
 *
 * Stored as the engine's raw payload shape (array) keyed by destination ZIP-5.
 * On hit, the array is rebuilt into a typed `CalculateResponse` via the SDK's
 * `fromArray` factory.
 *
 * We store the raw payload (not the readonly object) so the cache key shape
 * is portable across cache drivers (OpenCart 4.x supports file, apc, memcache,
 * redis) and survives SDK refactors.
 *
 * Cache key shape:
 *   - `ost:rate:{zip5}`           when no cart signature is supplied (legacy
 *                                  v0.1 shape; still callable for tests and
 *                                  for backwards-compat with stale entries).
 *   - `ost:rate:{zip5}:{sig}`     when a signature is supplied (v0.1.1+).
 *
 * Default TTL is 24h, configurable via the admin `cache_ttl_seconds` setting.
 */
final class RateCache
{
    public function __construct(
        private readonly CacheRepositoryInterface $cache,
        private readonly int $ttlSeconds,
    ) {
    }

    /**
     * Fetch from cache or compute via $resolver. Stores the response payload
     * on miss so subsequent calls within the TTL window hit the cache.
     *
     * @param callable():CalculateResponse $resolver
     */
    public function remember(string $zip5, callable $resolver, ?string $cartSignature = null): CalculateResponse
    {
        $key = self::keyFor($zip5, $cartSignature);
        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            /** @var array<string, mixed> $cached */
            return CalculateResponse::fromArray($cached);
        }

        $fresh = $resolver();
        $this->cache->set($key, self::responseToArray($fresh), $this->ttlSeconds);
        return $fresh;
    }

    /**
     * Compute the cache key for a destination ZIP-5 + optional cart signature.
     *
     * Null or empty signature → legacy v0.1 key shape, kept callable so the
     * extension reads stale entries from a v0.1 install gracefully (they'll
     * miss; TTL expiry takes care of cleanup).
     */
    public static function keyFor(string $zip5, ?string $cartSignature = null): string
    {
        $base = 'ost:rate:' . $zip5;
        return ($cartSignature === null || $cartSignature === '') ? $base : $base . ':' . $cartSignature;
    }

    /**
     * @return array<string, mixed>
     */
    private static function responseToArray(CalculateResponse $response): array
    {
        $lines = [];
        foreach ($response->lines as $line) {
            $jurisdictions = [];
            foreach ($line->jurisdictions as $j) {
                $jur = [
                    'name'     => $j->name,
                    'type'     => $j->type,
                    'rate_pct' => $j->ratePct,
                ];
                if ($j->tax !== null) {
                    $jur['tax'] = $j->tax;
                }
                $jurisdictions[] = $jur;
            }
            $lineArr = [
                'amount'        => $line->amount,
                'category'      => $line->category,
                'tax'           => $line->tax,
                'rate_pct'      => $line->ratePct,
                'jurisdictions' => $jurisdictions,
            ];
            if ($line->note !== null) {
                $lineArr['note'] = $line->note;
            }
            $lines[] = $lineArr;
        }
        return [
            'subtotal'   => $response->subtotal,
            'tax_total'  => $response->taxTotal,
            'lines'      => $lines,
            'disclaimer' => $response->disclaimer,
        ];
    }
}
