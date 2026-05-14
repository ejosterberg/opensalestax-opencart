<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace OpenSalesTax\OpenCart\Tests\Unit\Exceptions;

use OpenSalesTax\OpenCart\Exceptions\ConfigurationException;
use OpenSalesTax\OpenCart\Exceptions\OpenCartOpenSalesTaxException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ExceptionTest extends TestCase
{
    public function testBaseExceptionExtendsRuntime(): void
    {
        $e = new OpenCartOpenSalesTaxException('boom');
        self::assertInstanceOf(RuntimeException::class, $e);
    }

    public function testConfigurationExceptionIsAnOpenCartOpenSalesTaxException(): void
    {
        $e = new ConfigurationException('bad config');
        self::assertInstanceOf(OpenCartOpenSalesTaxException::class, $e);
        self::assertSame('bad config', $e->getMessage());
    }

    public function testWrapsPreviousException(): void
    {
        $previous = new RuntimeException('upstream');
        $e = new OpenCartOpenSalesTaxException('outer', 0, $previous);
        self::assertSame($previous, $e->getPrevious());
    }
}
