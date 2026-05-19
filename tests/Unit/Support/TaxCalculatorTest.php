<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\OpenCart\Tests\Unit\Support;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use OpenSalesTax\Client as SdkClient;
use OpenSalesTax\OpenCart\Exceptions\OpenCartOpenSalesTaxException;
use OpenSalesTax\OpenCart\Support\CartPayloadBuilder;
use OpenSalesTax\OpenCart\Support\ConfigBag;
use OpenSalesTax\OpenCart\Support\OpenSalesTaxClientFactory;
use OpenSalesTax\OpenCart\Support\RateCache;
use OpenSalesTax\OpenCart\Support\TaxCalculator;
use OpenSalesTax\OpenCart\Support\UrlValidator;
use OpenSalesTax\OpenCart\Tests\Stubs\ArrayCache;
use OpenSalesTax\OpenCart\Tests\Stubs\ArrayLogger;
use PHPUnit\Framework\TestCase;

final class TaxCalculatorTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private const PRODUCTS = [['total' => 100.00, 'quantity' => 1]];

    /** @var array<string, mixed> */
    private const SHIPPING_ADDRESS = ['iso_code_2' => 'US', 'postcode' => '55401'];

    public function testInactiveConfigReturnsNullImmediately(): void
    {
        $logger = new ArrayLogger();
        $calc = $this->buildCalculator(
            config: ConfigBag::fromArray([]), // disabled by default
            mockResponses: [],
            logger: $logger,
        );

        self::assertNull($calc->calculate(self::PRODUCTS, self::SHIPPING_ADDRESS, 'USD'));
        self::assertSame([], $logger->records);
    }

    public function testNonUsdReturnsNullWithoutCallingEngine(): void
    {
        $logger = new ArrayLogger();
        $mock = new MockHandler([]);
        $calc = $this->buildCalculatorWithMock(
            config: $this->activeConfig(),
            mock: $mock,
            logger: $logger,
        );

        self::assertNull($calc->calculate(self::PRODUCTS, self::SHIPPING_ADDRESS, 'EUR'));
        self::assertSame(0, $mock->count()); // no requests against the mock
    }

    public function testNonUsReturnsNullWithoutCallingEngine(): void
    {
        $logger = new ArrayLogger();
        $mock = new MockHandler([]);
        $calc = $this->buildCalculatorWithMock(
            config: $this->activeConfig(),
            mock: $mock,
            logger: $logger,
        );

        self::assertNull($calc->calculate(
            self::PRODUCTS,
            ['iso_code_2' => 'GB', 'postcode' => 'SW1A 1AA'],
            'USD',
        ));
        self::assertSame(0, $mock->count());
    }

    public function testHappyPathReturnsResponseAndCachesIt(): void
    {
        $logger = new ArrayLogger();
        $store = new ArrayCache();
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], $this->engineOk()),
        ]);
        $calc = $this->buildCalculatorWithMock(
            config: $this->activeConfig(),
            mock: $mock,
            logger: $logger,
            cacheStore: $store,
        );

        $response = $calc->calculate(self::PRODUCTS, self::SHIPPING_ADDRESS, 'USD');
        self::assertNotNull($response);
        self::assertSame('8.83', $response->taxTotal);
        self::assertSame(1, $store->setCount);
        self::assertSame(1, $logger->countAtLevel('info'));
        // v0.1.1+: cache key includes the cart signature suffix.
        $key = array_key_first($store->store);
        self::assertNotNull($key);
        self::assertMatchesRegularExpression('/^ost:rate:55401:[0-9a-f]{16}$/', $key);
    }

    public function testSecondCallHitsCacheWithoutEngineRoundTrip(): void
    {
        $logger = new ArrayLogger();
        $store = new ArrayCache();
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], $this->engineOk()),
            // No second response queued â€” a second engine call would throw.
        ]);
        $calc = $this->buildCalculatorWithMock(
            config: $this->activeConfig(),
            mock: $mock,
            logger: $logger,
            cacheStore: $store,
        );

        $first = $calc->calculate(self::PRODUCTS, self::SHIPPING_ADDRESS, 'USD');
        $second = $calc->calculate(self::PRODUCTS, self::SHIPPING_ADDRESS, 'USD');

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame($first->taxTotal, $second->taxTotal);
        self::assertSame(0, $mock->count()); // both responses consumed; not really useful
        self::assertSame(1, $logger->countAtLevel('info')); // only one engine call
    }

    public function testEngineErrorWithFailSoftReturnsNullAndLogsWarning(): void
    {
        $logger = new ArrayLogger();
        $mock = new MockHandler([
            new Response(500, ['Content-Type' => 'application/json'], '{"error":"oops"}'),
        ]);
        $calc = $this->buildCalculatorWithMock(
            config: $this->activeConfig(failHard: false),
            mock: $mock,
            logger: $logger,
        );

        self::assertNull($calc->calculate(self::PRODUCTS, self::SHIPPING_ADDRESS, 'USD'));
        self::assertSame(1, $logger->countAtLevel('warning'));
    }

    public function testEngineErrorWithFailHardThrows(): void
    {
        $logger = new ArrayLogger();
        $mock = new MockHandler([
            new Response(500, ['Content-Type' => 'application/json'], '{"error":"oops"}'),
        ]);
        $calc = $this->buildCalculatorWithMock(
            config: $this->activeConfig(failHard: true),
            mock: $mock,
            logger: $logger,
        );

        $this->expectException(OpenCartOpenSalesTaxException::class);
        $calc->calculate(self::PRODUCTS, self::SHIPPING_ADDRESS, 'USD');
    }

    public function testPrivateBaseUrlFailSoftReturnsNull(): void
    {
        $logger = new ArrayLogger();
        $mock = new MockHandler([]);
        $config = ConfigBag::fromArray([
            'status'   => true,
            'base_url' => 'http://10.0.0.1:8080',
        ]);
        $calc = $this->buildCalculatorWithMock(
            config: $config,
            mock: $mock,
            logger: $logger,
            validator: new UrlValidator(false, static fn (string $h): array => [$h]),
        );

        self::assertNull($calc->calculate(self::PRODUCTS, self::SHIPPING_ADDRESS, 'USD'));
        self::assertSame(0, $mock->count());
    }

    public function testEmptyCartReturnsNull(): void
    {
        $logger = new ArrayLogger();
        $mock = new MockHandler([]);
        $calc = $this->buildCalculatorWithMock(
            config: $this->activeConfig(),
            mock: $mock,
            logger: $logger,
        );

        self::assertNull($calc->calculate([], self::SHIPPING_ADDRESS, 'USD'));
        self::assertSame(0, $mock->count());
    }

    public function testTwoCartsWithDifferentSignaturesBothHitEngine(): void
    {
        $logger = new ArrayLogger();
        $store = new ArrayCache();
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], $this->engineOk()),
            new Response(200, ['Content-Type' => 'application/json'], $this->engineOk()),
        ]);
        $calc = $this->buildCalculatorWithMock(
            config: $this->activeConfig(),
            mock: $mock,
            logger: $logger,
            cacheStore: $store,
        );

        // Same ZIP, different category mix â€” must not share a cache entry.
        $cart1 = [['total' => 100.00]];
        $cart2 = [['total' => 50.00], ['total' => 50.00]];

        $r1 = $calc->calculate($cart1, self::SHIPPING_ADDRESS, 'USD');
        $r2 = $calc->calculate($cart2, self::SHIPPING_ADDRESS, 'USD');

        self::assertNotNull($r1);
        self::assertNotNull($r2);
        // Two distinct cache writes â€” no cross-cart cache hit.
        self::assertSame(2, $store->setCount);
        self::assertSame(2, $logger->countAtLevel('info'));
        self::assertCount(2, $store->store);
    }

    public function testExemptCustomerGroupShortCircuitsBeforeEngineCall(): void
    {
        $logger = new ArrayLogger();
        $mock = new MockHandler([]);  // any engine call would throw
        $config = ConfigBag::fromArray([
            'status'                     => true,
            'base_url'                   => 'https://ost.example.com',
            'allow_private_nets'         => true,
            'exempt_customer_group_ids'  => '2,3',
        ]);
        $calc = $this->buildCalculatorWithMock(
            config: $config,
            mock: $mock,
            logger: $logger,
        );

        self::assertNull($calc->calculate(self::PRODUCTS, self::SHIPPING_ADDRESS, 'USD', customerGroupId: 2));
        self::assertSame(0, $mock->count());
        self::assertSame([], $logger->records);
    }

    public function testNonExemptCustomerGroupFlowsNormally(): void
    {
        $logger = new ArrayLogger();
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], $this->engineOk()),
        ]);
        $config = ConfigBag::fromArray([
            'status'                     => true,
            'base_url'                   => 'https://ost.example.com',
            'allow_private_nets'         => true,
            'exempt_customer_group_ids'  => '2,3',
        ]);
        $calc = $this->buildCalculatorWithMock(
            config: $config,
            mock: $mock,
            logger: $logger,
        );

        $response = $calc->calculate(self::PRODUCTS, self::SHIPPING_ADDRESS, 'USD', customerGroupId: 1);
        self::assertNotNull($response);
    }

    public function testNullCustomerGroupBypassesExemptionGate(): void
    {
        $logger = new ArrayLogger();
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], $this->engineOk()),
        ]);
        $config = ConfigBag::fromArray([
            'status'                     => true,
            'base_url'                   => 'https://ost.example.com',
            'allow_private_nets'         => true,
            'exempt_customer_group_ids'  => '0,2',  // includes 0 (guests)
        ]);
        $calc = $this->buildCalculatorWithMock(
            config: $config,
            mock: $mock,
            logger: $logger,
        );

        // Null ID is the "unknown / guest fallback" path â€” exemption-list logic
        // requires a concrete ID to match, so we proceed to compute.
        $response = $calc->calculate(self::PRODUCTS, self::SHIPPING_ADDRESS, 'USD', customerGroupId: null);
        self::assertNotNull($response);
    }

    // --- CP-3 per-state nexus filter (v0.3.0) -------------------------------

    public function testNexusFilterEmptyAllowlistPreservesPreV03Behavior(): void
    {
        $logger = new ArrayLogger();
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], $this->engineOk()),
        ]);
        $config = ConfigBag::fromArray([
            'status'             => true,
            'base_url'           => 'https://ost.example.com',
            'allow_private_nets' => true,
            'nexus_states'       => '', // empty = filter disabled
        ]);
        $calc = $this->buildCalculatorWithMock(config: $config, mock: $mock, logger: $logger);

        // Even without a zone_code, the call goes through (filter is off).
        $response = $calc->calculate(self::PRODUCTS, self::SHIPPING_ADDRESS, 'USD');
        self::assertNotNull($response);
    }

    public function testNexusFilterAllowsCartInListedState(): void
    {
        $logger = new ArrayLogger();
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], $this->engineOk()),
        ]);
        $config = ConfigBag::fromArray([
            'status'             => true,
            'base_url'           => 'https://ost.example.com',
            'allow_private_nets' => true,
            'nexus_states'       => 'MN,WI,IA',
        ]);
        $calc = $this->buildCalculatorWithMock(config: $config, mock: $mock, logger: $logger);

        $response = $calc->calculate(
            self::PRODUCTS,
            ['iso_code_2' => 'US', 'postcode' => '55401', 'zone_code' => 'MN'],
            'USD',
        );
        self::assertNotNull($response);
    }

    public function testNexusFilterShortCircuitsOutOfStateCart(): void
    {
        $logger = new ArrayLogger();
        $mock = new MockHandler([]); // any engine call would throw
        $config = ConfigBag::fromArray([
            'status'             => true,
            'base_url'           => 'https://ost.example.com',
            'allow_private_nets' => true,
            'nexus_states'       => 'MN,WI,IA',
        ]);
        $calc = $this->buildCalculatorWithMock(config: $config, mock: $mock, logger: $logger);

        $response = $calc->calculate(
            self::PRODUCTS,
            ['iso_code_2' => 'US', 'postcode' => '94016', 'zone_code' => 'CA'],
            'USD',
        );
        self::assertNull($response);
        self::assertSame(0, $mock->count());
        self::assertSame(1, $logger->countAtLevel('info'));
    }

    public function testNexusFilterFailsClosedOnUnresolvableState(): void
    {
        $logger = new ArrayLogger();
        $mock = new MockHandler([]); // any engine call would throw
        $config = ConfigBag::fromArray([
            'status'             => true,
            'base_url'           => 'https://ost.example.com',
            'allow_private_nets' => true,
            'nexus_states'       => 'MN,WI,IA',
        ]);
        $calc = $this->buildCalculatorWithMock(config: $config, mock: $mock, logger: $logger);

        // No zone_code on the address: fail-closed when filter is active.
        $response = $calc->calculate(
            self::PRODUCTS,
            ['iso_code_2' => 'US', 'postcode' => '55401'],
            'USD',
        );
        self::assertNull($response);
        self::assertSame(0, $mock->count());
    }

    public function testNexusFilterAcceptsLowerCaseInput(): void
    {
        $logger = new ArrayLogger();
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], $this->engineOk()),
        ]);
        $config = ConfigBag::fromArray([
            'status'             => true,
            'base_url'           => 'https://ost.example.com',
            'allow_private_nets' => true,
            'nexus_states'       => 'mn, wi, ia',
        ]);
        $calc = $this->buildCalculatorWithMock(config: $config, mock: $mock, logger: $logger);

        $response = $calc->calculate(
            self::PRODUCTS,
            ['iso_code_2' => 'US', 'postcode' => '55401', 'zone_code' => 'mn'],
            'USD',
        );
        self::assertNotNull($response);
    }

    // --- end CP-3 -----------------------------------------------------------

    public function testEngineMalformedJsonFailsSoftByDefault(): void
    {
        $logger = new ArrayLogger();
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], 'not json'),
        ]);
        $calc = $this->buildCalculatorWithMock(
            config: $this->activeConfig(),
            mock: $mock,
            logger: $logger,
        );

        self::assertNull($calc->calculate(self::PRODUCTS, self::SHIPPING_ADDRESS, 'USD'));
        self::assertSame(1, $logger->countAtLevel('warning'));
    }

    private function activeConfig(bool $failHard = false): ConfigBag
    {
        return ConfigBag::fromArray([
            'status'             => true,
            'base_url'           => 'https://ost.example.com',
            'fail_hard'          => $failHard,
            'cache_ttl_seconds'  => 60,
            'allow_private_nets' => true,  // for the test validator below
        ]);
    }

    /**
     * @param array<int, mixed> $mockResponses
     */
    private function buildCalculator(
        ConfigBag $config,
        array $mockResponses,
        ArrayLogger $logger,
    ): TaxCalculator {
        $mock = new MockHandler($mockResponses);
        return $this->buildCalculatorWithMock($config, $mock, $logger);
    }

    private function buildCalculatorWithMock(
        ConfigBag $config,
        MockHandler $mock,
        ArrayLogger $logger,
        ?ArrayCache $cacheStore = null,
        ?UrlValidator $validator = null,
    ): TaxCalculator {
        $cacheStore ??= new ArrayCache();
        $validator ??= new UrlValidator(
            allowPrivateNets: false,
            hostResolver: static fn (string $host): array => ['8.8.8.8'],
        );

        $handler = HandlerStack::create($mock);
        $guzzle = new GuzzleClient(['handler' => $handler]);

        $factory = new class ($logger, $validator, $guzzle) extends OpenSalesTaxClientFactory {
            public function __construct(
                ArrayLogger $log,
                UrlValidator $validator,
                private readonly GuzzleClient $guzzle,
            ) {
                parent::__construct($log, $validator);
            }

            public function make(ConfigBag $config): ?SdkClient
            {
                // Re-run the parent validate path for the URL check, but if it
                // returned a Client, swap in our pre-mocked Guzzle handler.
                $real = parent::make($config);
                if ($real === null) {
                    return null;
                }
                return new SdkClient(
                    baseUrl: $config->baseUrl,
                    apiKey: $config->apiKey !== '' ? $config->apiKey : null,
                    httpClient: $this->guzzle,
                );
            }
        };

        return new TaxCalculator(
            config: $config,
            clientFactory: $factory,
            payloadBuilder: new CartPayloadBuilder(),
            cache: new RateCache($cacheStore, $config->cacheTtlSeconds),
            logger: $logger,
        );
    }

    private function engineOk(): string
    {
        return json_encode([
            'subtotal'   => '100.00',
            'tax_total'  => '8.83',
            'lines'      => [
                [
                    'amount'        => '100.00',
                    'category'      => 'general',
                    'tax'           => '8.83',
                    'rate_pct'      => '8.83',
                    'jurisdictions' => [
                        ['name' => 'Minnesota State', 'type' => 'state',  'rate_pct' => '6.875', 'tax' => '6.88'],
                        ['name' => 'Hennepin County', 'type' => 'county', 'rate_pct' => '1.955', 'tax' => '1.95'],
                    ],
                ],
            ],
            'disclaimer' => 'calc-only',
        ], JSON_THROW_ON_ERROR);
    }
}
