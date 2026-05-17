<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

/**
 * Smoke test: end-to-end call against the OpenSalesTax engine.
 *
 * Builds the connector's `TaxCalculator` with an in-memory cache + logger,
 * hands it a representative OpenCart cart payload, and prints the engine's
 * response. Intended to be run from the repo root with the engine URL in
 * an env var.
 *
 * Usage:
 *   OST_ENGINE_URL=http://10.32.161.126:8080 \
 *       php tools/smoke-test.php
 *
 * Exit codes:
 *   0 â€” engine returned non-zero tax for ZIP 55401 / $100
 *   1 â€” engine returned zero tax / connector yielded
 *   2 â€” engine error (network, HTTP, malformed JSON)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use OpenSalesTax\OpenCart\Support\CartPayloadBuilder;
use OpenSalesTax\OpenCart\Support\CacheRepositoryInterface;
use OpenSalesTax\OpenCart\Support\ConfigBag;
use OpenSalesTax\OpenCart\Support\LoggerInterface;
use OpenSalesTax\OpenCart\Support\OpenSalesTaxClientFactory;
use OpenSalesTax\OpenCart\Support\RateCache;
use OpenSalesTax\OpenCart\Support\TaxCalculator;
use OpenSalesTax\OpenCart\Support\UrlValidator;

$engineUrl = getenv('OST_ENGINE_URL') ?: 'http://10.32.161.126:8080';
$allowPrivate = filter_var(getenv('OST_ALLOW_PRIVATE') ?: '1', FILTER_VALIDATE_BOOLEAN);

$config = ConfigBag::fromArray([
    'status'             => true,
    'base_url'           => $engineUrl,
    'allow_private_nets' => $allowPrivate,
    'fail_hard'          => false,
    'cache_ttl_seconds'  => 0,
]);

// Anonymous in-memory cache + array logger (avoid pulling in OpenCart).
$cache = new class implements CacheRepositoryInterface {
    /** @var array<string, mixed> */
    private array $store = [];
    public function get(string $key): mixed
    {
        return $this->store[$key] ?? null;
    }
    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        $this->store[$key] = $value;
    }
    public function delete(string $key): void
    {
        unset($this->store[$key]);
    }
};

$logger = new class implements LoggerInterface {
    public function info(string $message, array $context = []): void
    {
        fwrite(STDERR, "INFO  $message " . json_encode($context) . "\n");
    }
    public function warning(string $message, array $context = []): void
    {
        fwrite(STDERR, "WARN  $message " . json_encode($context) . "\n");
    }
};

$calc = new TaxCalculator(
    config: $config,
    clientFactory: new OpenSalesTaxClientFactory($logger, new UrlValidator($allowPrivate)),
    payloadBuilder: new CartPayloadBuilder(),
    cache: new RateCache($cache, $config->cacheTtlSeconds),
    logger: $logger,
);

$products = [
    ['total' => 100.00, 'quantity' => 1, 'name' => 'Smoke-test SKU'],
];
$shipping = ['iso_code_2' => 'US', 'postcode' => '55401'];

fwrite(STDOUT, "OpenSalesTax connector smoke test\n");
fwrite(STDOUT, "  engine: $engineUrl\n");
fwrite(STDOUT, "  ZIP:    55401  amount: \$100.00\n\n");

try {
    $response = $calc->calculate($products, $shipping, 'USD');
} catch (\Throwable $e) {
    fwrite(STDERR, "ERROR " . $e->getMessage() . "\n");
    exit(2);
}

if ($response === null) {
    fwrite(STDERR, "Connector yielded (gate fail or fail-soft).\n");
    exit(1);
}

$tax = (float) $response->taxTotal;
fwrite(STDOUT, "tax_total: {$response->taxTotal}\n");
fwrite(STDOUT, "subtotal:  {$response->subtotal}\n");
fwrite(STDOUT, "lines:     " . count($response->lines) . "\n");
if ($response->disclaimer !== '') {
    fwrite(STDOUT, "disclaimer: {$response->disclaimer}\n");
}
exit($tax > 0.0 ? 0 : 1);
