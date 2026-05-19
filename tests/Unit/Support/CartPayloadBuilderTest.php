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
        [$address, $lineItems, $signature] = $result;
        self::assertSame('55401', $address->zip5);
        self::assertCount(1, $lineItems);
        self::assertSame('100.00', $lineItems[0]->amount);
        self::assertSame('general', $lineItems[0]->category);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $signature);
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

    public function testCartSignatureIsDeterministicForSameLines(): void
    {
        $r1 = $this->builder->build(
            products: [['total' => 100.00], ['total' => 25.00]],
            shippingAddress: ['iso_code_2' => 'US', 'postcode' => '55401'],
            currency: 'USD',
        );
        $r2 = $this->builder->build(
            products: [['total' => 100.00], ['total' => 25.00]],
            shippingAddress: ['iso_code_2' => 'US', 'postcode' => '55401'],
            currency: 'USD',
        );

        self::assertNotNull($r1);
        self::assertNotNull($r2);
        self::assertSame($r1[2], $r2[2]);
    }

    public function testCartSignatureIsOrderIndependent(): void
    {
        $r1 = $this->builder->build(
            products: [['total' => 50.00], ['total' => 25.00]],
            shippingAddress: ['iso_code_2' => 'US', 'postcode' => '55401'],
            currency: 'USD',
        );
        $r2 = $this->builder->build(
            products: [['total' => 25.00], ['total' => 50.00]],
            shippingAddress: ['iso_code_2' => 'US', 'postcode' => '55401'],
            currency: 'USD',
        );

        self::assertNotNull($r1);
        self::assertNotNull($r2);
        self::assertSame($r1[2], $r2[2]);
    }

    public function testCartSignatureDiffersOnDifferentAmounts(): void
    {
        $r1 = $this->builder->build(
            products: [['total' => 100.00]],
            shippingAddress: ['iso_code_2' => 'US', 'postcode' => '55401'],
            currency: 'USD',
        );
        $r2 = $this->builder->build(
            products: [['total' => 100.01]],
            shippingAddress: ['iso_code_2' => 'US', 'postcode' => '55401'],
            currency: 'USD',
        );

        self::assertNotNull($r1);
        self::assertNotNull($r2);
        self::assertNotSame($r1[2], $r2[2]);
    }

    public function testCartSignatureDiffersOnDifferentLineCount(): void
    {
        $r1 = $this->builder->build(
            products: [['total' => 100.00]],
            shippingAddress: ['iso_code_2' => 'US', 'postcode' => '55401'],
            currency: 'USD',
        );
        $r2 = $this->builder->build(
            products: [['total' => 50.00], ['total' => 50.00]],
            shippingAddress: ['iso_code_2' => 'US', 'postcode' => '55401'],
            currency: 'USD',
        );

        self::assertNotNull($r1);
        self::assertNotNull($r2);
        self::assertNotSame($r1[2], $r2[2]);
    }

    // --- CP-3 state extraction ---------------------------------------------

    public function testExtractStateReturnsUpperCaseTwoLetterCode(): void
    {
        self::assertSame('MN', CartPayloadBuilder::extractState(['zone_code' => 'MN']));
        self::assertSame('MN', CartPayloadBuilder::extractState(['zone_code' => 'mn']));
        self::assertSame('CA', CartPayloadBuilder::extractState(['zone_code' => ' CA ']));
    }

    public function testExtractStateReturnsNullForMissingOrInvalid(): void
    {
        self::assertNull(CartPayloadBuilder::extractState([]));
        self::assertNull(CartPayloadBuilder::extractState(['zone_code' => '']));
        self::assertNull(CartPayloadBuilder::extractState(['zone_code' => 'Minnesota']));
        self::assertNull(CartPayloadBuilder::extractState(['zone_code' => '12']));
    }

    public function testBuildReturnsStateAsFourthTupleElement(): void
    {
        $result = $this->builder->build(
            products: [['total' => 100.00]],
            shippingAddress: ['iso_code_2' => 'US', 'postcode' => '55401', 'zone_code' => 'MN'],
            currency: 'USD',
        );
        self::assertNotNull($result);
        self::assertSame('MN', $result[3]);
    }

    public function testBuildReturnsNullStateWhenZoneCodeMissing(): void
    {
        $result = $this->builder->build(
            products: [['total' => 100.00]],
            shippingAddress: ['iso_code_2' => 'US', 'postcode' => '55401'],
            currency: 'USD',
        );
        self::assertNotNull($result);
        self::assertNull($result[3]);
    }

    // CP-9 (v0.4.0): first-class shipping support — builder appends a typed
    // Shipping value object as the 5th tuple element when shippingCost > 0.

    public function testBuildOmitsShippingWhenCostNull(): void
    {
        $result = $this->builder->build(
            products: [['total' => 100.00]],
            shippingAddress: ['iso_code_2' => 'US', 'postcode' => '55401'],
            currency: 'USD',
        );
        self::assertNotNull($result);
        self::assertNull($result[4]);
    }

    public function testBuildOmitsShippingWhenCostZero(): void
    {
        $result = $this->builder->build(
            products: [['total' => 100.00]],
            shippingAddress: ['iso_code_2' => 'US', 'postcode' => '55401'],
            currency: 'USD',
            shippingCost: 0.0,
        );
        self::assertNotNull($result);
        self::assertNull($result[4]);
    }

    public function testBuildIncludesShippingWhenCostPositive(): void
    {
        $result = $this->builder->build(
            products: [['total' => 100.00]],
            shippingAddress: ['iso_code_2' => 'US', 'postcode' => '55401'],
            currency: 'USD',
            shippingCost: 12.50,
        );
        self::assertNotNull($result);
        self::assertInstanceOf(\OpenSalesTax\Shipping::class, $result[4]);
        self::assertSame('12.50', $result[4]->amount);
        self::assertTrue($result[4]->separatelyStated);
        self::assertFalse($result[4]->isHandlingCharge);
    }

    public function testSignatureChangesWhenShippingChanges(): void
    {
        $a = $this->builder->build(
            products: [['total' => 100.00]],
            shippingAddress: ['iso_code_2' => 'US', 'postcode' => '55401'],
            currency: 'USD',
            shippingCost: 12.50,
        );
        $b = $this->builder->build(
            products: [['total' => 100.00]],
            shippingAddress: ['iso_code_2' => 'US', 'postcode' => '55401'],
            currency: 'USD',
            shippingCost: 15.00,
        );
        $c = $this->builder->build(
            products: [['total' => 100.00]],
            shippingAddress: ['iso_code_2' => 'US', 'postcode' => '55401'],
            currency: 'USD',
        );
        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertNotNull($c);
        self::assertNotSame($a[2], $b[2]);
        self::assertNotSame($a[2], $c[2]);
    }
}
