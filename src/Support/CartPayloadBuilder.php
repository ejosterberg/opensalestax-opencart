<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace OpenSalesTax\OpenCart\Support;

use OpenSalesTax\Address;
use OpenSalesTax\Exceptions\OpenSalesTaxValidationException;
use OpenSalesTax\LineItem;
use OpenSalesTax\Shipping;

/**
 * Build SDK `Address` + `LineItem[]` from an OpenCart cart shape.
 *
 * Inputs:
 *  - $products: the array returned by `$this->cart->getProducts()` — each
 *               entry has at least `total` (line total, decimal), `quantity`,
 *               `tax_class_id`, `name`.
 *  - $shippingAddress: `$this->session->data['shipping_address']` shape —
 *                      a flat array with `iso_code_2` (country) and
 *                      `postcode`. We only need those two for v0.1.
 *  - $currency: the cart's currency code (USD required).
 *
 * Output: a tuple `[Address, LineItem[]]` ready to hand to the SDK, OR null
 * if the gate fails (non-US country / non-USD currency / missing ZIP /
 * empty cart). The TaxCalculator translates null into "yield to OpenCart".
 */
final class CartPayloadBuilder
{
    private const COUNTRY_US = 'US';
    private const CURRENCY_USD = 'USD';

    public function __construct(
        private readonly ZipExtractor $zipExtractor = new ZipExtractor(),
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $products
     * @param array<string, mixed> $shippingAddress
     * @param string $currency
     * @param float|null $shippingCost Pre-tax shipping amount in cart currency,
     *     typically `$session->data['shipping_method']['cost']` from the OpenCart
     *     catalog session. When > 0, a `Shipping` value object is included in the
     *     returned tuple so the engine applies first-class shipping-tax rules
     *     (engine v0.59.0+). Null or zero → no shipping included.
     *
     * @return array{0: Address, 1: LineItem[], 2: string, 3: string|null, 4: Shipping|null}|null Tuple of
     *     [Address, LineItem[], cartSignature, stateCode, shipping]. The signature is a stable
     *     16-hex-char prefix of SHA-256 over the sorted `(category, amount)`
     *     tuples — used by `RateCache` to keep mixed-category carts at the
     *     same ZIP from colliding on a stale cached response.
     *     The stateCode is the upper-case 2-letter US state code extracted from
     *     OpenCart's `zone_code` (CP-3 v0.3 nexus filter); null if unresolvable.
     */
    public function build(
        array $products,
        array $shippingAddress,
        string $currency,
        ?float $shippingCost = null,
    ): ?array {
        $zip5 = $this->extractEligibleZip($shippingAddress, $currency);
        if ($zip5 === null) {
            return null;
        }
        $lineItems = $this->buildLineItems($products);
        if ($lineItems === []) {
            return null;
        }
        $shipping = $this->buildShipping($shippingCost);
        return $this->safeAddressTuple($zip5, $lineItems, self::extractState($shippingAddress), $shipping);
    }

    /**
     * Construct a typed Shipping value-object from the raw cart shipping
     * cost (PHP float in cart currency). Returns null when the cost is null,
     * zero, or non-positive — engine treats absent shipping as "no shipping
     * line", which is what we want when shipping is free or undefined.
     */
    private function buildShipping(?float $shippingCost): ?Shipping
    {
        if ($shippingCost === null || $shippingCost <= 0.0) {
            return null;
        }
        try {
            return new Shipping(
                amount: number_format($shippingCost, 2, '.', ''),
                separatelyStated: true,
            );
        } catch (OpenSalesTaxValidationException) {
            return null;
        }
    }

    /**
     * Extract the US state 2-letter code from an OpenCart shipping_address
     * array. OpenCart populates `zone_code` (e.g. "MN") at checkout time
     * from the `oc_zone` table when the user picks a region. Returns null
     * if the field is missing or not a 2-letter code.
     *
     * @param array<string, mixed> $shippingAddress
     */
    public static function extractState(array $shippingAddress): ?string
    {
        $raw = $shippingAddress['zone_code'] ?? $shippingAddress['zone'] ?? null;
        if (!is_string($raw)) {
            return null;
        }
        $upper = strtoupper(trim($raw));
        if (preg_match('/^[A-Z]{2}$/', $upper) === 1) {
            return $upper;
        }
        return null;
    }

    /**
     * Compute the cart signature for an arbitrary line-item list (+ optional shipping).
     *
     * Deterministic: same `(category, amount)` set → same digest, regardless
     * of order. Different categories OR different amounts → different digest.
     * Shipping contributes a separate `ship:amount` token so a cart with
     * a different shipping cost (or shipping vs. no-shipping) gets a fresh
     * cache key.
     *
     * 16 hex chars (8 bytes) is enough collision resistance for a per-ZIP
     * cache: a merchant would need millions of distinct cart shapes per ZIP
     * inside the same TTL window before the birthday-paradox risk approaches
     * 1%. Shorter prefix keeps OpenCart cache backends (file, apcu, memcache)
     * happy.
     *
     * @param LineItem[] $lineItems
     */
    public static function signatureFor(array $lineItems, ?Shipping $shipping = null): string
    {
        $tuples = [];
        foreach ($lineItems as $item) {
            $tuples[] = $item->category . ':' . $item->amount;
        }
        sort($tuples);
        if ($shipping !== null) {
            $tuples[] = 'ship:' . $shipping->amount;
        }
        return substr(hash('sha256', implode('|', $tuples)), 0, 16);
    }

    /**
     * Build the SDK Address and pair it with the prepared line items + the
     * optional Shipping value object.
     * Returns null on any SDK validation rejection (unreachable in practice
     * — the ZipExtractor already guarantees `^\d{5}$` — but the SDK throws
     * a typed exception we catch to keep the boundary clean).
     *
     * @param LineItem[] $lineItems
     * @return array{0: Address, 1: LineItem[], 2: string, 3: string|null, 4: Shipping|null}|null
     */
    private function safeAddressTuple(
        string $zip5,
        array $lineItems,
        ?string $stateCode,
        ?Shipping $shipping,
    ): ?array {
        try {
            return [new Address($zip5), $lineItems, self::signatureFor($lineItems, $shipping), $stateCode, $shipping];
        } catch (OpenSalesTaxValidationException) {
            return null;
        }
    }

    /**
     * Returns the 5-digit ZIP iff currency, country, and postcode all pass
     * the gate; null otherwise.
     *
     * @param array<string, mixed> $shippingAddress
     */
    private function extractEligibleZip(array $shippingAddress, string $currency): ?string
    {
        $currencyOk = strtoupper($currency) === self::CURRENCY_USD;
        $countryRaw = $shippingAddress['iso_code_2'] ?? $shippingAddress['country_code'] ?? '';
        $countryOk  = is_string($countryRaw)
            && strtoupper(trim($countryRaw)) === self::COUNTRY_US;
        $rawPostcode = $shippingAddress['postcode'] ?? '';
        $postcodeOk  = is_string($rawPostcode);

        if (!$currencyOk || !$countryOk || !$postcodeOk) {
            return null;
        }
        return $this->zipExtractor->extract($rawPostcode);
    }

    /**
     * @param array<int, array<string, mixed>> $products
     * @return LineItem[]
     */
    private function buildLineItems(array $products): array
    {
        $items = [];
        foreach ($products as $product) {
            $amount = $this->lineAmount($product);
            if ($amount === null) {
                continue;
            }
            try {
                $items[] = new LineItem(amount: $amount, category: 'general');
            } catch (OpenSalesTaxValidationException) {
                // Skip the line if the SDK rejects it (negative, non-numeric).
                continue;
            }
        }
        return $items;
    }

    /**
     * Coerce an OpenCart product entry's line total into a decimal string the
     * SDK accepts.
     *
     * OC's $product['total'] is the line total (price * quantity), already
     * decimal. We accept int/float/string-numeric and convert via
     * `number_format` to a fixed 2-decimal-place string with no thousands
     * separator.
     *
     * @param array<string, mixed> $product
     */
    private function lineAmount(array $product): ?string
    {
        $raw = $product['total'] ?? null;
        if ($raw === null || !is_numeric($raw)) {
            return null;
        }
        $float = (float) $raw;
        return $float < 0.0 ? null : number_format($float, 2, '.', '');
    }
}
