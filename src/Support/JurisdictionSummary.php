<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\OpenCart\Support;

use OpenSalesTax\Responses\CalculateResponse;

/**
 * One row in the per-jurisdiction tax breakdown.
 *
 * Built from the SDK's `CalculateResponse` by grouping `CalculatedLine.jurisdictions`
 * across every line and summing the per-line `tax` strings. The numeric value
 * is held as a float for arithmetic â€” re-formatted to 2dp by the caller when
 * emitting to OpenCart's totals array.
 *
 * `type` mirrors the engine's jurisdiction-type vocabulary: `state`, `county`,
 * `city`, `special`. Other values pass through untouched and group under
 * their own bucket.
 */
final readonly class JurisdictionSummary
{
    /**
     * Sort offsets for the standard US jurisdiction types â€” state first,
     * special last, matching typical tax-receipt ordering.
     */
    public const SORT_OFFSET = [
        'state'   => 0,
        'county'  => 1,
        'city'    => 2,
        'special' => 3,
    ];

    public function __construct(
        public string $name,
        public string $type,
        public float $taxAmount,
    ) {
    }

    /**
     * Group `CalculateResponse.lines[].jurisdictions[]` across all lines into
     * one summary per unique `(name, type)` pair. Returns the summaries in
     * stable order: by type-sort-offset first, then by first-seen name.
     *
     * If the per-jurisdiction tax sums drift below the engine's authoritative
     * `tax_total` (rounding boundary), the last summary absorbs the remainder
     * so the OpenCart-visible total still ties to the engine's number.
     *
     * @return JurisdictionSummary[]
     */
    public static function fromResponse(CalculateResponse $response): array
    {
        /** @var array<string, array{name: string, type: string, tax: float, order: int}> $buckets */
        $buckets = [];
        $insertionIndex = 0;

        foreach ($response->lines as $line) {
            foreach ($line->jurisdictions as $j) {
                if ($j->tax === null) {
                    // Rate-only jurisdictions (no per-line tax) cannot be
                    // surfaced as their own total line.
                    continue;
                }
                $key = $j->type . '|' . $j->name;
                if (!isset($buckets[$key])) {
                    $buckets[$key] = [
                        'name'  => $j->name,
                        'type'  => $j->type,
                        'tax'   => 0.0,
                        'order' => $insertionIndex++,
                    ];
                }
                $buckets[$key]['tax'] += (float) $j->tax;
            }
        }

        if ($buckets === []) {
            return [];
        }

        // Round each bucket to 2dp first; THEN compute drift against the rounded
        // sum so the absorber sees the actual gap a shopper would notice (e.g.,
        // engine returns 9.025, raw sum is 9.025, but 6.875 rounds to 6.88
        // injecting +0.005 of phantom tax â€” that's what we absorb away).
        $bucketList = array_values($buckets);
        foreach ($bucketList as &$b) {
            $b['tax'] = round($b['tax'], 2);
        }
        unset($b);

        $sum = 0.0;
        foreach ($bucketList as $b) {
            $sum += $b['tax'];
        }
        $authoritative = (float) $response->taxTotal;
        $drift = $authoritative - $sum;
        // Use a half-cent threshold; the abs() guards against the floating-point
        // noise that a true-zero drift would produce in PHP's IEEE-754 doubles.
        if (abs($drift) >= 0.005 - PHP_FLOAT_EPSILON) {
            $bucketList[count($bucketList) - 1]['tax'] += $drift;
        }

        usort(
            $bucketList,
            static function (array $a, array $b): int {
                $oa = self::SORT_OFFSET[$a['type']] ?? PHP_INT_MAX;
                $ob = self::SORT_OFFSET[$b['type']] ?? PHP_INT_MAX;
                return $oa <=> $ob ?: $a['order'] <=> $b['order'];
            },
        );

        return array_map(
            static fn (array $b): self => new self($b['name'], $b['type'], $b['tax']),
            $bucketList,
        );
    }
}
