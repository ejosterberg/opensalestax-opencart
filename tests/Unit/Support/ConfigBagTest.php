<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace OpenSalesTax\OpenCart\Tests\Unit\Support;

use OpenSalesTax\OpenCart\Support\ConfigBag;
use PHPUnit\Framework\TestCase;

final class ConfigBagTest extends TestCase
{
    public function testDefaultsAreSafe(): void
    {
        $bag = ConfigBag::fromArray([]);

        self::assertFalse($bag->enabled);
        self::assertSame('', $bag->baseUrl);
        self::assertSame('', $bag->apiKey);
        self::assertSame(10.0, $bag->timeoutSeconds);
        self::assertTrue($bag->tlsVerify);
        self::assertFalse($bag->allowPrivateNets);
        self::assertFalse($bag->failHard);
        self::assertSame(86400, $bag->cacheTtlSeconds);
        self::assertFalse($bag->isActive());
    }

    public function testIsActiveRequiresEnabledAndBaseUrl(): void
    {
        self::assertFalse(ConfigBag::fromArray(['status' => true])->isActive());
        self::assertFalse(ConfigBag::fromArray(['base_url' => 'https://x.example'])->isActive());
        self::assertTrue(
            ConfigBag::fromArray([
                'status'   => true,
                'base_url' => 'https://ost.example.com',
            ])->isActive(),
        );
    }

    public function testBoolCoercionAcceptsStringsAndInts(): void
    {
        self::assertTrue(ConfigBag::fromArray(['status' => '1'])->enabled);
        self::assertTrue(ConfigBag::fromArray(['status' => 'true'])->enabled);
        self::assertTrue(ConfigBag::fromArray(['status' => 'YES'])->enabled);
        self::assertTrue(ConfigBag::fromArray(['status' => 1])->enabled);
        self::assertFalse(ConfigBag::fromArray(['status' => '0'])->enabled);
        self::assertFalse(ConfigBag::fromArray(['status' => 'false'])->enabled);
        self::assertFalse(ConfigBag::fromArray(['status' => 0])->enabled);
    }

    public function testFloatCoercionAcceptsNumericStrings(): void
    {
        self::assertSame(5.5, ConfigBag::fromArray(['timeout_seconds' => '5.5'])->timeoutSeconds);
        self::assertSame(7.0, ConfigBag::fromArray(['timeout_seconds' => 7])->timeoutSeconds);
        self::assertSame(10.0, ConfigBag::fromArray(['timeout_seconds' => 'not-a-number'])->timeoutSeconds);
    }

    public function testIntCoercion(): void
    {
        self::assertSame(3600, ConfigBag::fromArray(['cache_ttl_seconds' => '3600'])->cacheTtlSeconds);
        self::assertSame(86400, ConfigBag::fromArray(['cache_ttl_seconds' => 'oops'])->cacheTtlSeconds);
    }

    public function testStringTrimsWhitespace(): void
    {
        $bag = ConfigBag::fromArray(['base_url' => '  https://ost.example.com  ']);
        self::assertSame('https://ost.example.com', $bag->baseUrl);
    }

    public function testExemptCustomerGroupIdsDefaultsToEmpty(): void
    {
        self::assertSame([], ConfigBag::fromArray([])->exemptCustomerGroupIds);
        self::assertSame([], ConfigBag::fromArray(['exempt_customer_group_ids' => ''])->exemptCustomerGroupIds);
        self::assertSame([], ConfigBag::fromArray(['exempt_customer_group_ids' => null])->exemptCustomerGroupIds);
    }

    public function testExemptCustomerGroupIdsParsesCsv(): void
    {
        self::assertSame(
            [2, 3, 7],
            ConfigBag::fromArray(['exempt_customer_group_ids' => '2,3,7'])->exemptCustomerGroupIds,
        );
    }

    public function testExemptCustomerGroupIdsTrimsAndDedupes(): void
    {
        self::assertSame(
            [2, 3],
            ConfigBag::fromArray(['exempt_customer_group_ids' => '  2 , 3 ,3 '])->exemptCustomerGroupIds,
        );
    }

    public function testExemptCustomerGroupIdsSortsAscending(): void
    {
        self::assertSame(
            [1, 2, 5, 9],
            ConfigBag::fromArray(['exempt_customer_group_ids' => '9,2,5,1'])->exemptCustomerGroupIds,
        );
    }

    public function testExemptCustomerGroupIdsAcceptsGuestZero(): void
    {
        self::assertSame(
            [0],
            ConfigBag::fromArray(['exempt_customer_group_ids' => '0'])->exemptCustomerGroupIds,
        );
    }

    public function testExemptCustomerGroupIdsDropsNonNumericTokens(): void
    {
        self::assertSame(
            [2, 7],
            ConfigBag::fromArray(['exempt_customer_group_ids' => '2,abc,7, '])->exemptCustomerGroupIds,
        );
    }

    public function testExemptCustomerGroupIdsAcceptsArrayInput(): void
    {
        self::assertSame(
            [2, 3, 7],
            ConfigBag::fromArray(['exempt_customer_group_ids' => [2, '3', 7]])->exemptCustomerGroupIds,
        );
    }

    public function testFullPopulatedShape(): void
    {
        $bag = ConfigBag::fromArray([
            'status'              => '1',
            'base_url'            => 'https://ost.example.com',
            'api_key'             => 'secret-token',
            'timeout_seconds'     => '4.5',
            'tls_verify'          => '0',
            'allow_private_nets'  => '1',
            'fail_hard'           => '1',
            'cache_ttl_seconds'   => '60',
        ]);

        self::assertTrue($bag->enabled);
        self::assertSame('https://ost.example.com', $bag->baseUrl);
        self::assertSame('secret-token', $bag->apiKey);
        self::assertSame(4.5, $bag->timeoutSeconds);
        self::assertFalse($bag->tlsVerify);
        self::assertTrue($bag->allowPrivateNets);
        self::assertTrue($bag->failHard);
        self::assertSame(60, $bag->cacheTtlSeconds);
        self::assertTrue($bag->isActive());
    }
}
