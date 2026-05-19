<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\OpenCart\Support;

use OpenSalesTax\Client;
use OpenSalesTax\OpenCart\Exceptions\OpenCartOpenSalesTaxException;
use OpenSalesTax\Responses\CalculateResponse;
use Throwable;

/**
 * Top-level coordinator. The OpenCart glue (order-total model) constructs
 * one of these per request and asks it to compute tax for the active cart.
 *
 * Pipeline:
 *  1. Inert-fast-path: if `ConfigBag::isActive()` is false, return null.
 *  2. Build payload (US + USD + valid-ZIP gate inside `CartPayloadBuilder`);
 *     if the builder returns null, return null.
 *  3. Build SDK client; if the factory returns null (URL rejected,
 *     fail-soft), return null.
 *  4. Cache lookup keyed on ZIP-5; on miss, call SDK `calculate()`.
 *  5. Engine error: fail-soft logs and returns null; fail-hard rethrows.
 *
 * Returns `CalculateResponse` on success, `null` on any "yield to OpenCart"
 * outcome. Never returns a partial result.
 */
final class TaxCalculator
{
    public function __construct(
        private readonly ConfigBag $config,
        private readonly OpenSalesTaxClientFactory $clientFactory,
        private readonly CartPayloadBuilder $payloadBuilder,
        private readonly RateCache $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Read-only accessor for the underlying ConfigBag. Lets the OpenCart
     * order-total glue branch on settings (e.g. per-jurisdiction surface)
     * without re-reading `oc_setting` rows.
     */
    public function getConfig(): ConfigBag
    {
        return $this->config;
    }

    /**
     * @param array<int, array<string, mixed>> $products       OpenCart cart product array
     * @param array<string, mixed>             $shippingAddress OpenCart shipping_address shape
     * @param string                            $currency        Cart currency code
     * @param int|null                          $customerGroupId OpenCart customer-group ID of the logged-in
     *                                                           customer (or null when unknown / guest fallback).
     *                                                           When the bag's exempt list contains this ID, we
     *                                                           return null before touching the engine.
     */
    public function calculate(
        array $products,
        array $shippingAddress,
        string $currency,
        ?int $customerGroupId = null,
    ): ?CalculateResponse {
        $prepared = $this->prepare($products, $shippingAddress, $currency, $customerGroupId);
        if ($prepared === null) {
            return null;
        }
        [$client, $address, $lineItems, $signature, $stateCode] = $prepared;

        // Per-state nexus filter (CP-3, v0.3.0). When configured, short-circuit
        // the engine call for any cart shipping to a state outside the
        // merchant's nexus list. Fail-closed on unresolvable state.
        if ($this->shouldSkipForNexus($stateCode)) {
            $this->logger->info('opensalestax: nexus-filter short-circuited engine call', [
                'state' => $stateCode ?? '(unresolvable)',
                'nexus' => implode(',', $this->config->nexusStates),
            ]);
            return null;
        }

        try {
            return $this->cache->remember(
                $address->zip5,
                fn (): CalculateResponse => $this->callEngine($client, $address->zip5, $lineItems),
                $signature,
            );
        } catch (Throwable $e) {
            return $this->handleEngineError($e, $address->zip5);
        }
    }

    /**
     * Returns true when the per-state nexus filter is enabled AND the
     * destination state is NOT in the allowlist (or is unresolvable).
     */
    private function shouldSkipForNexus(?string $stateCode): bool
    {
        if ($this->config->nexusStates === []) {
            return false; // filter disabled
        }
        if ($stateCode === null) {
            return true; // fail-closed when filter is on
        }
        return !in_array($stateCode, $this->config->nexusStates, true);
    }

    /**
     * Run the inert / gate / client-factory chain. Returns `[client, address,
     * lineItems, signature]` when the pipeline is ready to call the engine,
     * or null when any prerequisite fails (extension off, gate-rejected,
     * URL rejected, exempt customer group).
     *
     * @param array<int, array<string, mixed>> $products
     * @param array<string, mixed>             $shippingAddress
     * @return array{0: Client, 1: \OpenSalesTax\Address, 2: \OpenSalesTax\LineItem[], 3: string, 4: string|null}|null
     */
    private function prepare(
        array $products,
        array $shippingAddress,
        string $currency,
        ?int $customerGroupId,
    ): ?array {
        if (!$this->config->isActive()) {
            return null;
        }
        if ($customerGroupId !== null && in_array($customerGroupId, $this->config->exemptCustomerGroupIds, true)) {
            return null;
        }
        $payload = $this->payloadBuilder->build($products, $shippingAddress, $currency);
        $client  = $payload === null ? null : $this->clientFactory->make($this->config);
        if ($payload === null || $client === null) {
            return null;
        }
        [$address, $lineItems, $signature, $stateCode] = $payload;
        return [$client, $address, $lineItems, $signature, $stateCode];
    }

    /**
     * @param \OpenSalesTax\LineItem[] $lineItems
     */
    private function callEngine(Client $client, string $zip5, array $lineItems): CalculateResponse
    {
        $start = microtime(true);
        // SDK exceptions (network, API, validation) flow up unchanged. The
        // outer `calculate()` catches them via the Throwable handler and
        // applies the fail-soft / fail-hard policy in `handleEngineError()`.
        $response = $client->calculate(new \OpenSalesTax\Address($zip5), $lineItems);

        $this->logger->info('opensalestax: engine /v1/calculate ok', [
            'zip5'       => $zip5,
            'rtt_ms'     => (int) round((microtime(true) - $start) * 1000),
            'line_count' => count($response->lines),
        ]);
        return $response;
    }

    /**
     * Fail-soft / fail-hard handler for engine errors raised inside the cache
     * resolver. Logs structured metadata in both modes; rethrows only when
     * fail-hard is on.
     */
    private function handleEngineError(Throwable $e, string $zip5): null
    {
        $this->logger->warning('opensalestax: engine call failed; applying fail-soft policy', [
            'zip5'      => $zip5,
            'fail_hard' => $this->config->failHard ? 1 : 0,
            'error'     => $e->getMessage(),
        ]);

        if ($this->config->failHard) {
            throw new OpenCartOpenSalesTaxException(
                'OpenSalesTax engine unreachable; checkout blocked (fail-hard mode).',
                0,
                $e,
            );
        }
        return null;
    }
}
