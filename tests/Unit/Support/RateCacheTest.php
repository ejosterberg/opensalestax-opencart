<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace OpenSalesTax\OpenCart\Tests\Unit\Support;

use OpenSalesTax\OpenCart\Support\RateCache;
use OpenSalesTax\OpenCart\Tests\Stubs\ArrayCache;
use OpenSalesTax\Responses\CalculateResponse;
use OpenSalesTax\Responses\CalculatedLine;
use OpenSalesTax\Responses\JurisdictionRate;
use PHPUnit\Framework\TestCase;

final class RateCacheTest extends TestCase
{
    public function testKeyShape(): void
    {
        self::assertSame('ost:rate:55401', RateCache::keyFor('55401'));
    }

    public function testMissTriggersResolverAndStoresPayload(): void
    {
        $store = new ArrayCache();
        $cache = new RateCache($store, 60);

        $response = $this->makeResponse();
        $resolved = $cache->remember('55401', fn (): CalculateResponse => $response);

        self::assertSame($response->taxTotal, $resolved->taxTotal);
        self::assertSame(1, $store->setCount);
        self::assertSame(60, $store->lastTtl);
        self::assertArrayHasKey('ost:rate:55401', $store->store);
    }

    public function testHitShortCircuitsResolver(): void
    {
        $store = new ArrayCache();
        $cache = new RateCache($store, 60);

        $resolverInvocations = 0;
        $maker = function () use (&$resolverInvocations): CalculateResponse {
            $resolverInvocations++;
            return $this->makeResponse();
        };

        $cache->remember('55401', $maker);
        $cache->remember('55401', $maker);

        self::assertSame(1, $resolverInvocations);
        self::assertSame(1, $store->setCount);
    }

    public function testNonArrayCachedValueFallsBackToResolver(): void
    {
        $store = new ArrayCache();
        $store->store['ost:rate:55401'] = 'garbage';
        $cache = new RateCache($store, 60);

        $resolved = $cache->remember('55401', fn (): CalculateResponse => $this->makeResponse());
        // We get a fresh response and the cache slot gets overwritten with valid payload.
        self::assertSame('8.83', $resolved->taxTotal);
        self::assertIsArray($store->store['ost:rate:55401']);
    }

    public function testRoundTripPreservesJurisdictionsAndDisclaimer(): void
    {
        $store = new ArrayCache();
        $cache = new RateCache($store, 3600);

        $cache->remember('55401', fn (): CalculateResponse => $this->makeResponse());
        // Force a second call: this hits cache and reconstructs from payload array.
        $reloaded = $cache->remember('55401', fn (): CalculateResponse => $this->makeResponse());

        self::assertSame('100.00', $reloaded->subtotal);
        self::assertSame('8.83', $reloaded->taxTotal);
        self::assertCount(1, $reloaded->lines);
        self::assertSame('100.00', $reloaded->lines[0]->amount);
        self::assertCount(2, $reloaded->lines[0]->jurisdictions);
        self::assertSame('Minnesota State', $reloaded->lines[0]->jurisdictions[0]->name);
        self::assertSame('calc-only', $reloaded->disclaimer);
    }

    private function makeResponse(): CalculateResponse
    {
        return new CalculateResponse(
            subtotal: '100.00',
            taxTotal: '8.83',
            lines: [
                new CalculatedLine(
                    amount: '100.00',
                    category: 'general',
                    tax: '8.83',
                    ratePct: '8.83',
                    jurisdictions: [
                        new JurisdictionRate(name: 'Minnesota State', type: 'state', ratePct: '6.875', tax: '6.88'),
                        new JurisdictionRate(name: 'Hennepin County', type: 'county', ratePct: '1.955', tax: '1.95'),
                    ],
                    note: null,
                ),
            ],
            disclaimer: 'calc-only',
        );
    }
}
