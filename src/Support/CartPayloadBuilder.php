<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace OpenSalesTax\OpenCart\Support;

use OpenSalesTax\Address;
use OpenSalesTax\Exceptions\OpenSalesTaxValidationException;
use OpenSalesTax\LineItem;

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
     *
     * @return array{0: Address, 1: LineItem[]}|null
     */
    public function build(array $products, array $shippingAddress, string $currency): ?array
    {
        $zip5 = $this->extractEligibleZip($shippingAddress, $currency);
        if ($zip5 === null) {
            return null;
        }
        $lineItems = $this->buildLineItems($products);
        if ($lineItems === []) {
            return null;
        }
        return $this->safeAddressTuple($zip5, $lineItems);
    }

    /**
     * Build the SDK Address and pair it with the prepared line items.
     * Returns null on any SDK validation rejection (unreachable in practice
     * — the ZipExtractor already guarantees `^\d{5}$` — but the SDK throws
     * a typed exception we catch to keep the boundary clean).
     *
     * @param LineItem[] $lineItems
     * @return array{0: Address, 1: LineItem[]}|null
     */
    private function safeAddressTuple(string $zip5, array $lineItems): ?array
    {
        try {
            return [new Address($zip5), $lineItems];
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
