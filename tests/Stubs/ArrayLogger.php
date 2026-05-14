<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace OpenSalesTax\OpenCart\Tests\Stubs;

use OpenSalesTax\OpenCart\Support\LoggerInterface;

/**
 * In-memory logger double for tests.
 *
 * Captures every call so tests can assert on log messages without depending
 * on PSR-3, Monolog, or OpenCart's logger.
 */
final class ArrayLogger implements LoggerInterface
{
    /** @var array<int, array{level: string, message: string, context: array<string, scalar|null>}> */
    public array $records = [];

    public function info(string $message, array $context = []): void
    {
        $this->records[] = ['level' => 'info', 'message' => $message, 'context' => $context];
    }

    public function warning(string $message, array $context = []): void
    {
        $this->records[] = ['level' => 'warning', 'message' => $message, 'context' => $context];
    }

    public function countAtLevel(string $level): int
    {
        $matches = array_filter($this->records, static fn (array $r): bool => $r['level'] === $level);
        return count($matches);
    }
}
