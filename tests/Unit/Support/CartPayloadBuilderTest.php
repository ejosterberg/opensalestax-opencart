<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace OpenSalesTax\OpenCart\Tests\Unit\Support;

use OpenSalesTax\OpenCart\Support\CartPayloadBuilder;
use PHPUnit\Framework\TestCase;

final class CartPayloadBuilderTest extends TestCase
{
    private CartPayloadBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new CartPayloadBuilder();
    }

    public function testHappyPathSingleLineUsUsdReturnsTuple(): void
    {
        $result = $this->builder->build(
            products: [['total' => 100.00, 'quantity' => 1]],
            shippingAddress: ['iso_code_2' => 'US', 'postcode' => '55401'],
            currency: 'USD',
        );

        self::assertNotNull($result);
        [$address, $lineItems] = $result;
        self::assertSame('55401', $address->zip5);
        self::assertCount(1, $lineItems);
        self::assertSame('100.00', $lineItems[0]->amount);
        self::assertSame('general', $lineItems[0]->category);
    }

    public function testMultipleLinesMaintainOrder(): void
    {
        $result = $this->builder->build(
            products: [
                ['total' => 50.00],
                ['total' => 25.50],
                ['total' => 0.99],
            ],
            shippingAddress: ['iso_code_2' => 'US', 'postcode' => '55401'],
            currency: 'USD',
        );

        self::assertNotNull($result);
        [, $lineItems] = $result;
        self::assertCount(3, $lineItems);
        self::assertSame('50.00', $lineItems[0]->amount);
        self::assertSame('25.50', $lineItems[1]->amount);
        self::assertSame('0.99', $lineItems[2]->amount);
    }

    public function testNonUsdReturnsNull(): void
    {
        self::assertNull($this->builder->build(
            products: [['total' => 100.00]],
            shippingAddress: ['iso_code_2' => 'US', 'postcode' => '55401'],
            currency: 'EUR',
        ));
    }

    public function testNonUsCountryReturnsNull(): void
    {
        self::assertNull($this->builder->build(
            products: [['total' => 100.00]],
            shippingAddress: ['iso_code_2' => 'GB', 'postcode' => 'SW1A 1AA'],
            currency: 'USD',
        ));
    }

    public function testMissingCountryReturnsNull(): void
    {
        self::assertNull($this->builder->build(
            products: [['total' => 100.00]],
            shippingAddress: ['postcode' => '55401'],
            currency: 'USD',
        ));
    }

    public function testMissingPostcodeReturnsNull(): void
    {
        self::assertNull($this->builder->build(
            products: [['total' => 100.00]],
            shippingAddress: ['iso_code_2' => 'US'],
            currency: 'USD',
        ));
    }

    public function testMalformedPostcodeReturnsNull(): void
    {
        self::assertNull($this->builder->build(
            products: [['total' => 100.00]],
            shippingAddress: ['iso_code_2' => 'US', 'postcode' => 'OOPS'],
            currency: 'USD',
        ));
    }

    public function testZipPlus4IsNormalized(): void
    {
        $result = $this->builder->build(
            products: [['total' => 100.00]],
            shippingAddress: ['iso_code_2' => 'US', 'postcode' => '55401-1234'],
            currency: 'USD',
        );

        self::assertNotNull($result);
        [$address] = $result;
        self::assertSame('55401', $address->zip5);
    }

    public function testEmptyCartReturnsNull(): void
    {
        self::assertNull($this->builder->build(
            products: [],
            shippingAddress: ['iso_code_2' => 'US', 'postcode' => '55401'],
            currency: 'USD',
        ));
    }

    public function testNegativeAmountIsSkipped(): void
    {
        $result = $this->builder->build(
            products: [
                ['total' => -10.00],
                ['total' => 25.00],
            ],
            shippingAddress: ['iso_code_2' => 'US', 'postcode' => '55401'],
            currency: 'USD',
        );

        self::assertNotNull($result);
        [, $lineItems] = $result;
        self::assertCount(1, $lineItems);
        self::assertSame('25.00', $lineItems[0]->amount);
    }

    public function testNonNumericAmountIsSkipped(): void
    {
        $result = $this->builder->build(
            products: [
                ['total' => 'not a number'],
                ['total' => 25.00],
            ],
            shippingAddress: ['iso_code_2' => 'US', 'postcode' => '55401'],
            currency: 'USD',
        );

        self::assertNotNull($result);
        [, $lineItems] = $result;
        self::assertCount(1, $lineItems);
    }

    public function testStringNumericAmountIsAccepted(): void
    {
        $result = $this->builder->build(
            products: [['total' => '42.50']],
            shippingAddress: ['iso_code_2' => 'US', 'postcode' => '55401'],
            currency: 'USD',
        );

        self::assertNotNull($result);
        [, $lineItems] = $result;
        self::assertSame('42.50', $lineItems[0]->amount);
    }

    public function testLowercaseUsdIsAccepted(): void
    {
        $result = $this->builder->build(
            products: [['total' => 100.00]],
            shippingAddress: ['iso_code_2' => 'us', 'postcode' => '55401'],
            currency: 'usd',
        );

        self::assertNotNull($result);
    }

    public function testNonStringCountryReturnsNull(): void
    {
        self::assertNull($this->builder->build(
            products: [['total' => 100.00]],
            shippingAddress: ['iso_code_2' => 42, 'postcode' => '55401'],
            currency: 'USD',
        ));
    }
}
