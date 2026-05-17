<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\OpenCart\Tests\Unit\Support;

use OpenSalesTax\OpenCart\Support\ZipExtractor;
use PHPUnit\Framework\TestCase;

final class ZipExtractorTest extends TestCase
{
    private ZipExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new ZipExtractor();
    }

    public function testEmptyReturnsNull(): void
    {
        self::assertNull($this->extractor->extract(''));
    }

    public function testCleanZip5Roundtrips(): void
    {
        self::assertSame('55401', $this->extractor->extract('55401'));
    }

    public function testZipPlus4IsTrimmed(): void
    {
        self::assertSame('55401', $this->extractor->extract('55401-1234'));
    }

    public function testZipWithSpaceIsTrimmed(): void
    {
        self::assertSame('55401', $this->extractor->extract('55401 1234'));
    }

    public function testZipWithSurroundingWhitespace(): void
    {
        self::assertSame('55401', $this->extractor->extract('   55401   '));
    }

    public function testZipWithStatePrefix(): void
    {
        self::assertSame('55401', $this->extractor->extract('MN 55401'));
    }

    public function testNonUsPostcodeReturnsNull(): void
    {
        self::assertNull($this->extractor->extract('K1A 0B1'));
        self::assertNull($this->extractor->extract('SW1A 1AA'));
    }

    public function testFourDigitsReturnsNull(): void
    {
        self::assertNull($this->extractor->extract('1234'));
    }

    public function testEmojiAndSymbolsDoNotMatch(): void
    {
        self::assertNull($this->extractor->extract('!!!@#@$'));
    }
}
