<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\OpenCart\Support;

/**
 * Tiny cache port.
 *
 * Mirrors a subset of PSR-16 (get / set / delete) so the testable units don't
 * need PSR-16 itself. OpenCart 4.x ships its own `\Cache` class with `get` /
 * `set` / `delete` â€” the extension glue provides an adapter that forwards
 * verbatim.
 *
 * Tests substitute an in-memory implementation.
 */
interface CacheRepositoryInterface
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value, int $ttlSeconds): void;

    public function delete(string $key): void;
}
