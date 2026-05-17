<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\OpenCart\Support;

/**
 * Normalize a customer-supplied postcode into a 5-digit US ZIP.
 *
 * OpenCart stores the shipping postcode as a free-text string from the
 * checkout form. We can see values like:
 *   "55401"
 *   "55401-1234"
 *   "55401 1234"
 *   " 55401 "
 *   "MN 55401"
 *   "" (empty â€” fall back to billing)
 *   "K1A 0B1" (Canadian, will be rejected by the gate's US check, but we
 *               want to bail gracefully if we ever see it here)
 *
 * Strategy: pull the first contiguous run of 5 digits. Anything else returns
 * null and the caller bails (fail-soft).
 */
final class ZipExtractor
{
    public function extract(string $rawPostcode): ?string
    {
        if ($rawPostcode === '') {
            return null;
        }
        if (preg_match('/\d{5}/', $rawPostcode, $matches) === 1) {
            return $matches[0];
        }
        return null;
    }
}
