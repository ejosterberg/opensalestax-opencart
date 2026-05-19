<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace Opencart\Catalog\Model\Extension\Opensalestax\Total;

use OpenSalesTax\OpenCart\Exceptions\OpenCartOpenSalesTaxException;
use OpenSalesTax\OpenCart\Support\JurisdictionSummary;
use OpenSalesTax\OpenCart\Support\TaxCalculator;
use OpenSalesTax\Responses\CalculateResponse;

/**
 * OpenCart 4.x order-total entry point.
 *
 * Called by `Cart\Total::getTotals()` during cart-totals computation. We
 * inspect the live cart + shipping address, hand off to the testable
 * `TaxCalculator`, and on success write a `taxes` entry plus the running
 * total.
 *
 * Gate failures (non-US / non-USD / disabled / engine error in fail-soft
 * mode) return without touching $taxes or $total â€” OpenCart's built-in
 * tax flow continues unchanged.
 */
class Opensalestax extends \Opencart\System\Engine\Model
{
    private const SORT_ORDER_KEY = 'module_opensalestax_sort_order';
    private const DEFAULT_SORT_ORDER = 5;
    private const TAX_LINE_TITLE = 'Sales Tax';

    /**
     * Language keys for per-jurisdiction line titles. Falls back to the
     * engine-provided jurisdiction name when a key is missing â€” keeps unusual
     * jurisdictions (the engine may add new types) renderable.
     */
    private const PER_JURISDICTION_TITLE_KEY = [
        'state'   => 'title_state_tax',
        'county'  => 'title_county_tax',
        'city'    => 'title_city_tax',
        'special' => 'title_special_tax',
    ];

    /**
     * Code suffix per jurisdiction type. Combined with `opensalestax_` to form
     * unique OpenCart total-line codes â€” required because the totals array is
     * keyed by `code` in checkout.
     */
    private const PER_JURISDICTION_CODE = [
        'state'   => 'opensalestax_state',
        'county'  => 'opensalestax_county',
        'city'    => 'opensalestax_city',
        'special' => 'opensalestax_special',
    ];

    /**
     * @param array<int, array<string, mixed>> $totals  Running totals list
     *                                                  (modified in place by reference).
     * @param array<string, float|int>         $taxes   Per-tax-class running totals.
     *                                                  Keyed by tax_class_id.
     * @param array<string, mixed>             $total   Aggregate total carrier.
     */
    public function getTotal(array &$totals, array &$taxes, array &$total): void
    {
        if (!$this->config->get('module_opensalestax_status')) {
            return;
        }

        $calculator = $this->buildCalculatorSafely();
        if (!$calculator instanceof TaxCalculator) {
            return;
        }

        $response = $this->computeResponse($calculator);
        if ($response === null) {
            return;
        }
        $this->applyResponse($calculator, $response, $totals, $total);
    }

    /**
     * Ask the calculator for a response. Returns null on any "yield to
     * OpenCart" outcome (fail-soft default), and rethrows
     * `OpenCartOpenSalesTaxException` only when the caller has opted into
     * fail-hard mode.
     *
     * @throws OpenCartOpenSalesTaxException When the calculator is in
     *     fail-hard mode and the engine is unreachable.
     */
    private function computeResponse(TaxCalculator $calculator): ?CalculateResponse
    {
        $products      = $this->cart->getProducts();
        $shipping      = $this->extractShippingAddress();
        $currency      = $this->extractCurrencyCode();
        $customerGroup = $this->extractCustomerGroupId();
        $shippingCost  = $this->extractShippingCost();

        try {
            return $calculator->calculate($products, $shipping, $currency, $customerGroup, $shippingCost);
        } catch (OpenCartOpenSalesTaxException $e) {
            // Fail-hard mode bubbles out so OpenCart can surface a real error.
            throw $e;
        } catch (\Throwable $e) {
            $this->logFailSoft('opensalestax: calculate failed', $e);
            return null;
        }
    }

    /**
     * Pull the chosen shipping method's pre-tax cost from the OpenCart
     * checkout session. OpenCart's `total.shipping` order-total runs before
     * us (sort_order < ours), so by the time we're called the merchant has
     * picked a method and `$session->data['shipping_method']['cost']` is
     * populated with the cart-currency amount.
     *
     * Returns null when no shipping method is selected (cart-level only
     * carts, free shipping with no row, etc.).
     */
    private function extractShippingCost(): ?float
    {
        $method = $this->session->data['shipping_method'] ?? null;
        if (!is_array($method)) {
            return null;
        }
        $cost = $method['cost'] ?? null;
        if (!is_numeric($cost)) {
            return null;
        }
        return (float) $cost;
    }

    /**
     * Run the bootstrap autoload + builder in a try/catch so an unexpected
     * failure (autoload, OC contract drift) degrades to fail-soft.
     */
    private function buildCalculatorSafely(): ?TaxCalculator
    {
        try {
            require_once DIR_EXTENSION . 'opensalestax/system/library/opensalestax/bootstrap.php'; // NOSONAR â€” bundled bootstrap is the entry point
            return \OpensalestaxBootstrap::build($this->registry);
        } catch (\Throwable $e) {
            $this->logFailSoft('opensalestax: build failed', $e);
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function extractShippingAddress(): array
    {
        $raw = $this->session->data['shipping_address'] ?? [];
        return is_array($raw) ? $raw : [];
    }

    private function extractCurrencyCode(): string
    {
        $currency = (string) ($this->session->data['currency'] ?? '');
        if ($currency === '' && method_exists($this->currency, 'getCode')) {
            $currency = (string) $this->currency->getCode();
        }
        return $currency;
    }

    /**
     * Best-effort read of the logged-in customer's group ID. Returns null when
     * the contract drifts (no `getGroupId()` method) or when reading throws.
     * The calculator interprets null as "no exemption check" and proceeds.
     */
    private function extractCustomerGroupId(): ?int
    {
        try {
            if (!isset($this->customer) || !method_exists($this->customer, 'getGroupId')) {
                return null;
            }
            $value = $this->customer->getGroupId();
            return is_numeric($value) ? (int) $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $totals
     * @param array<string, mixed>             $total
     */
    private function applyResponse(
        TaxCalculator $calculator,
        CalculateResponse $response,
        array &$totals,
        array &$total,
    ): void {
        $itemTax     = (float) $response->taxTotal;
        $shippingTax = $response->shipping !== null ? (float) $response->shipping->taxAmount : 0.0;
        $taxAmount   = $itemTax + $shippingTax;
        if ($taxAmount <= 0.0) {
            return;
        }

        $sortOrder = (int) ($this->config->get(self::SORT_ORDER_KEY) ?: self::DEFAULT_SORT_ORDER);
        $bag       = $calculator->getConfig();

        if ($bag->perJurisdictionLines) {
            $summaries = JurisdictionSummary::fromResponse($response);
            if ($summaries !== []) {
                foreach ($summaries as $summary) {
                    $totals[] = $this->jurisdictionTotalRow($summary, $sortOrder);
                }
                if ($shippingTax > 0.0) {
                    $totals[] = $this->shippingTaxRow($shippingTax, $sortOrder);
                }
                $total['total'] = (float) ($total['total'] ?? 0.0) + $taxAmount;
                return;
            }
            // Fall through to the aggregate line when the engine returned
            // no surfaceable per-jurisdiction tax (e.g. note-only lines).
        }

        $totals[] = [
            'extension'  => 'opensalestax',
            'code'       => 'opensalestax',
            'title'      => self::TAX_LINE_TITLE,
            'value'      => $taxAmount,
            'sort_order' => $sortOrder,
        ];

        // Increment the platform's running total. We deliberately do NOT
        // populate a tax_class_id key in `$taxes` because doing so would
        // conflict with merchant-defined tax classes; we surface our
        // computed tax as its own first-class total line(s) instead.
        $total['total'] = (float) ($total['total'] ?? 0.0) + $taxAmount;
    }

    /**
     * Build a "Shipping Tax" total row. Used in per-jurisdiction mode where
     * we want shipping tax surfaced as its own line so the merchant's
     * accounting can break it out.
     *
     * @return array<string, mixed>
     */
    private function shippingTaxRow(float $shippingTax, int $baseSortOrder): array
    {
        return [
            'extension'  => 'opensalestax',
            'code'       => 'opensalestax_shipping',
            'title'      => 'Shipping Tax',
            'value'      => $shippingTax,
            'sort_order' => $baseSortOrder + 8, // after special-district sort offset (which is 9 typically)
        ];
    }

    /**
     * Build a single OpenCart totals-array row for one jurisdiction.
     *
     * @return array<string, mixed>
     */
    private function jurisdictionTotalRow(JurisdictionSummary $summary, int $baseSortOrder): array
    {
        $code   = self::PER_JURISDICTION_CODE[$summary->type] ?? ('opensalestax_' . $summary->type);
        $titleKey = self::PER_JURISDICTION_TITLE_KEY[$summary->type] ?? null;
        $rendered = $titleKey !== null ? $this->renderTitle($titleKey, $summary->name) : $summary->name;
        $offset = JurisdictionSummary::SORT_OFFSET[$summary->type] ?? 9;

        return [
            'extension'  => 'opensalestax',
            'code'       => $code,
            'title'      => $rendered,
            'value'      => $summary->taxAmount,
            'sort_order' => $baseSortOrder + $offset,
        ];
    }

    /**
     * Render a per-jurisdiction title using the language file's pattern, or
     * fall back to the jurisdiction's literal name if the language layer
     * isn't initialized in this request (e.g. headless/CLI testing).
     */
    private function renderTitle(string $titleKey, string $jurisdictionName): string
    {
        try {
            $this->load->language('extension/opensalestax/total/opensalestax');
            $pattern = (string) $this->language->get($titleKey);
            if ($pattern !== '' && $pattern !== $titleKey) {
                return sprintf($pattern, $jurisdictionName);
            }
        } catch (\Throwable) {
            // ignore â€” fall back to the jurisdiction name
        }
        return $jurisdictionName;
    }

    /**
     * Best-effort fail-soft logging. Never throws.
     */
    private function logFailSoft(string $message, \Throwable $e): void
    {
        try {
            if (method_exists($this->log, 'write')) {
                $this->log->write($message . ' ' . $e->getMessage());
            }
        } catch (\Throwable) {
            // intentionally silent â€” fail-soft means do not block checkout
        }
    }
}
