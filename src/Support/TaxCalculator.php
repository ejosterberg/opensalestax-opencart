<?php

// SPDX-License-Identifier: Apache-2.0

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
     * @param array<int, array<string, mixed>> $products       OpenCart cart product array
     * @param array<string, mixed>             $shippingAddress OpenCart shipping_address shape
     * @param string                            $currency        Cart currency code
     */
    public function calculate(array $products, array $shippingAddress, string $currency): ?CalculateResponse
    {
        $prepared = $this->prepare($products, $shippingAddress, $currency);
        if ($prepared === null) {
            return null;
        }
        [$client, $address, $lineItems] = $prepared;

        try {
            return $this->cache->remember(
                $address->zip5,
                fn (): CalculateResponse => $this->callEngine($client, $address->zip5, $lineItems),
            );
        } catch (Throwable $e) {
            return $this->handleEngineError($e, $address->zip5);
        }
    }

    /**
     * Run the inert / gate / client-factory chain. Returns `[client, address,
     * lineItems]` when the pipeline is ready to call the engine, or null when
     * any prerequisite fails (extension off, gate-rejected, URL rejected).
     *
     * @param array<int, array<string, mixed>> $products
     * @param array<string, mixed>             $shippingAddress
     * @return array{0: Client, 1: \OpenSalesTax\Address, 2: \OpenSalesTax\LineItem[]}|null
     */
    private function prepare(array $products, array $shippingAddress, string $currency): ?array
    {
        if (!$this->config->isActive()) {
            return null;
        }
        $payload = $this->payloadBuilder->build($products, $shippingAddress, $currency);
        $client  = $payload === null ? null : $this->clientFactory->make($this->config);
        if ($payload === null || $client === null) {
            return null;
        }
        [$address, $lineItems] = $payload;
        return [$client, $address, $lineItems];
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
