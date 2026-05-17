<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

use OpenSalesTax\OpenCart\Support\CacheRepositoryInterface;

/**
 * Forwards `CacheRepositoryInterface` calls to OpenCart 4.x's `\Cache`.
 *
 * OpenCart's `Cache::get($key)` returns false on miss (and any deserialized
 * value on hit). We normalize the miss to null so RateCache's `is_array`
 * branch can short-circuit cleanly.
 *
 * The TTL parameter is forwarded as the second argument to `Cache::set` â€”
 * OC's cache backends honor it where supported (file, apc, redis, memcache).
 */
final class OpenCartCacheAdapter implements CacheRepositoryInterface
{
    /** @param object $cache OpenCart's `\Opencart\System\Library\Cache`. */
    public function __construct(private readonly object $cache)
    {
    }

    public function get(string $key): mixed
    {
        if (!method_exists($this->cache, 'get')) {
            return null;
        }
        $value = $this->cache->get($key);
        return $value === false ? null : $value;
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        if (method_exists($this->cache, 'set')) {
            $this->cache->set($key, $value, $ttlSeconds);
        }
    }

    public function delete(string $key): void
    {
        if (method_exists($this->cache, 'delete')) {
            $this->cache->delete($key);
        }
    }
}
