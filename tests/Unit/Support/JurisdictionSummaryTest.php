<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace OpenSalesTax\OpenCart\Tests\Unit\Support;

use OpenSalesTax\OpenCart\Support\JurisdictionSummary;
use OpenSalesTax\Responses\CalculateResponse;
use OpenSalesTax\Responses\CalculatedLine;
use OpenSalesTax\Responses\JurisdictionRate;
use PHPUnit\Framework\TestCase;

final class JurisdictionSummaryTest extends TestCase
{
    public function testEmptyResponseReturnsEmpty(): void
    {
        $response = new CalculateResponse(
            subtotal: '0.00',
            taxTotal: '0.00',
            lines: [],
            disclaimer: 'x',
        );
        self::assertSame([], JurisdictionSummary::fromResponse($response));
    }

    public function testSingleLineMultiJurisdictionGroups(): void
    {
        $response = new CalculateResponse(
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
                ),
            ],
            disclaimer: 'x',
        );

        $summaries = JurisdictionSummary::fromResponse($response);
        self::assertCount(2, $summaries);
        self::assertSame('Minnesota State', $summaries[0]->name);
        self::assertSame('state', $summaries[0]->type);
        self::assertEqualsWithDelta(6.88, $summaries[0]->taxAmount, 0.001);
        self::assertSame('Hennepin County', $summaries[1]->name);
        self::assertSame('county', $summaries[1]->type);
        self::assertEqualsWithDelta(1.95, $summaries[1]->taxAmount, 0.001);
    }

    public function testMultipleLinesSumPerJurisdiction(): void
    {
        $response = new CalculateResponse(
            subtotal: '200.00',
            taxTotal: '17.66',
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
                ),
                new CalculatedLine(
                    amount: '100.00',
                    category: 'clothing',
                    tax: '8.83',
                    ratePct: '8.83',
                    jurisdictions: [
                        new JurisdictionRate(name: 'Minnesota State', type: 'state', ratePct: '6.875', tax: '6.88'),
                        new JurisdictionRate(name: 'Hennepin County', type: 'county', ratePct: '1.955', tax: '1.95'),
                    ],
                ),
            ],
            disclaimer: 'x',
        );

        $summaries = JurisdictionSummary::fromResponse($response);
        self::assertCount(2, $summaries);
        self::assertSame('state', $summaries[0]->type);
        self::assertEqualsWithDelta(13.76, $summaries[0]->taxAmount, 0.001);
        self::assertSame('county', $summaries[1]->type);
        self::assertEqualsWithDelta(3.90, $summaries[1]->taxAmount, 0.001);
    }

    public function testSortOrderStateBeforeCountyBeforeCity(): void
    {
        $response = new CalculateResponse(
            subtotal: '100.00',
            taxTotal: '9.00',
            lines: [
                new CalculatedLine(
                    amount: '100.00',
                    category: 'general',
                    tax: '9.00',
                    ratePct: '9.00',
                    jurisdictions: [
                        // Engine returns out-of-order on purpose:
                        new JurisdictionRate(name: 'Minneapolis', type: 'city', ratePct: '0.50', tax: '0.50'),
                        new JurisdictionRate(name: 'Minnesota State', type: 'state', ratePct: '7.00', tax: '7.00'),
                        new JurisdictionRate(name: 'Hennepin County', type: 'county', ratePct: '1.50', tax: '1.50'),
                    ],
                ),
            ],
            disclaimer: 'x',
        );

        $summaries = JurisdictionSummary::fromResponse($response);
        self::assertCount(3, $summaries);
        self::assertSame('state', $summaries[0]->type);
        self::assertSame('county', $summaries[1]->type);
        self::assertSame('city', $summaries[2]->type);
    }

    public function testJurisdictionWithoutTaxIsSkipped(): void
    {
        $response = new CalculateResponse(
            subtotal: '100.00',
            taxTotal: '6.88',
            lines: [
                new CalculatedLine(
                    amount: '100.00',
                    category: 'general',
                    tax: '6.88',
                    ratePct: '6.88',
                    jurisdictions: [
                        new JurisdictionRate(name: 'Minnesota State', type: 'state', ratePct: '6.88', tax: '6.88'),
                        // Rate-only — no tax key, e.g. exemption rule:
                        new JurisdictionRate(name: 'Special Information', type: 'special', ratePct: '0.00', tax: null),
                    ],
                ),
            ],
            disclaimer: 'x',
        );

        $summaries = JurisdictionSummary::fromResponse($response);
        self::assertCount(1, $summaries);
        self::assertSame('state', $summaries[0]->type);
    }

    public function testLastSummaryAbsorbsRoundingDrift(): void
    {
        $response = new CalculateResponse(
            subtotal: '100.00',
            taxTotal: '8.85', // engine authoritative
            lines: [
                new CalculatedLine(
                    amount: '100.00',
                    category: 'general',
                    tax: '8.85',
                    ratePct: '8.85',
                    jurisdictions: [
                        new JurisdictionRate(name: 'Minnesota State', type: 'state', ratePct: '6.88', tax: '6.88'),
                        // Per-jurisdiction sums to 8.83; engine total is 8.85 — drift of $0.02.
                        new JurisdictionRate(name: 'Hennepin County', type: 'county', ratePct: '1.95', tax: '1.95'),
                    ],
                ),
            ],
            disclaimer: 'x',
        );

        $summaries = JurisdictionSummary::fromResponse($response);
        self::assertCount(2, $summaries);
        $sum = array_sum(array_map(static fn (JurisdictionSummary $s): float => $s->taxAmount, $summaries));
        self::assertEqualsWithDelta(8.85, $sum, 0.001);
    }

    /**
     * Real-world fixture from the VM 919 live-engine integration test:
     * MN state 6.875% + county/city/3 special-district lines for a
     * $100 MN/55401 cart. Engine returns `tax_total=9.025`. Each
     * jurisdiction's raw tax rounds individually to 2dp — but state
     * 6.875 round-half-up to 6.88 injects +0.005 of phantom tax,
     * making the naive sum 9.03 instead of 9.025. The absorber must
     * push that drift onto the last bucket so the aggregate matches
     * the engine's authoritative number.
     */
    public function testRoundHalfUpDriftIsAbsorbedAfterPerBucketRound(): void
    {
        $response = new CalculateResponse(
            subtotal: '100.00',
            taxTotal: '9.025', // engine authoritative — 3dp
            lines: [
                new CalculatedLine(
                    amount: '100.00',
                    category: 'general',
                    tax: '9.025',
                    ratePct: '9.025',
                    jurisdictions: [
                        new JurisdictionRate(name: 'Minnesota', type: 'state', ratePct: '6.875', tax: '6.875'),
                        new JurisdictionRate(name: 'Hennepin County', type: 'county', ratePct: '0.15', tax: '0.15'),
                        new JurisdictionRate(name: 'Minneapolis', type: 'city', ratePct: '0.50', tax: '0.50'),
                        new JurisdictionRate(name: 'Transit District', type: 'special', ratePct: '0.50', tax: '0.50'),
                        new JurisdictionRate(name: 'Housing District', type: 'special', ratePct: '0.50', tax: '0.50'),
                        new JurisdictionRate(name: 'Stadium District', type: 'special', ratePct: '0.50', tax: '0.50'),
                    ],
                ),
            ],
            disclaimer: 'x',
        );

        $summaries = JurisdictionSummary::fromResponse($response);
        self::assertCount(6, $summaries);
        $sum = array_sum(array_map(static fn (JurisdictionSummary $s): float => $s->taxAmount, $summaries));
        self::assertEqualsWithDelta(9.025, $sum, 0.001);
    }
}
