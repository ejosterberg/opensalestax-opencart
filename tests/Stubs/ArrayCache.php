<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace OpenSalesTax\OpenCart\Tests\Stubs;

use OpenSalesTax\OpenCart\Support\CacheRepositoryInterface;

/**
 * In-memory cache double for tests.
 *
 * Does not honor TTL (every set is effectively forever) — tests assert on
 * the TTL parameter via the `lastTtl` field if needed.
 */
final class ArrayCache implements CacheRepositoryInterface
{
    /** @var array<string, mixed> */
    public array $store = [];
    public ?int $lastTtl = null;
    public int $setCount = 0;
    public int $getCount = 0;

    public function get(string $key): mixed
    {
        $this->getCount++;
        return $this->store[$key] ?? null;
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        $this->setCount++;
        $this->lastTtl = $ttlSeconds;
        $this->store[$key] = $value;
    }

    public function delete(string $key): void
    {
        unset($this->store[$key]);
    }
}
