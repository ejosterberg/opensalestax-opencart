<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Opencart\Catalog\Model\Extension\Opensalestax\Total;

use OpenSalesTax\OpenCart\Exceptions\OpenCartOpenSalesTaxException;
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
 * mode) return without touching $taxes or $total — OpenCart's built-in
 * tax flow continues unchanged.
 */
class Opensalestax extends \Opencart\System\Engine\Model
{
    private const SORT_ORDER_KEY = 'module_opensalestax_sort_order';
    private const DEFAULT_SORT_ORDER = 5;
    private const TAX_LINE_TITLE = 'Sales Tax';

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

        $response = $this->computeResponse();
        if ($response === null) {
            return;
        }
        $this->applyResponse($response, $totals, $total);
    }

    /**
     * Build the calculator pipeline and ask it for a response. Returns null
     * on any "yield to OpenCart" outcome (fail-soft default), and rethrows
     * `OpenCartOpenSalesTaxException` only when the caller has opted into
     * fail-hard mode.
     *
     * @throws OpenCartOpenSalesTaxException When the calculator is in
     *     fail-hard mode and the engine is unreachable.
     */
    private function computeResponse(): ?CalculateResponse
    {
        $calculator = $this->buildCalculatorSafely();
        if (!$calculator instanceof TaxCalculator) {
            return null;
        }

        $products      = $this->cart->getProducts();
        $shipping      = $this->extractShippingAddress();
        $currency      = $this->extractCurrencyCode();
        $customerGroup = $this->extractCustomerGroupId();

        try {
            return $calculator->calculate($products, $shipping, $currency, $customerGroup);
        } catch (OpenCartOpenSalesTaxException $e) {
            // Fail-hard mode bubbles out so OpenCart can surface a real error.
            throw $e;
        } catch (\Throwable $e) {
            $this->logFailSoft('opensalestax: calculate failed', $e);
            return null;
        }
    }

    /**
     * Run the bootstrap autoload + builder in a try/catch so an unexpected
     * failure (autoload, OC contract drift) degrades to fail-soft.
     */
    private function buildCalculatorSafely(): ?TaxCalculator
    {
        try {
            require_once DIR_EXTENSION . 'opensalestax/system/library/opensalestax/bootstrap.php'; // NOSONAR — bundled bootstrap is the entry point
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
    private function applyResponse(CalculateResponse $response, array &$totals, array &$total): void
    {
        $taxAmount = (float) $response->taxTotal;
        if ($taxAmount <= 0.0) {
            return;
        }

        $sortOrder = (int) ($this->config->get(self::SORT_ORDER_KEY) ?: self::DEFAULT_SORT_ORDER);

        $totals[] = [
            'extension'  => 'opensalestax',
            'code'       => 'opensalestax',
            'title'      => self::TAX_LINE_TITLE,
            'value'      => $taxAmount,
            'sort_order' => $sortOrder,
        ];

        // Increment the platform's running total. We deliberately do NOT
        // populate a tax_class_id key in `$taxes` for v0.1 because doing so
        // would conflict with merchant-defined tax classes; we surface our
        // computed tax as its own first-class total line instead.
        $total['total'] = (float) ($total['total'] ?? 0.0) + $taxAmount;
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
            // intentionally silent — fail-soft means do not block checkout
        }
    }
}
