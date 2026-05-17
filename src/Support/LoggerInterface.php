<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\OpenCart\Support;

/**
 * Tiny PSR-3-shaped logger port.
 *
 * OpenCart's `\Log` is not PSR-3 (no level argument, no context). Rather than
 * make the testable units depend on OpenCart's class hierarchy, we depend on
 * this interface and the extension glue provides an adapter that forwards to
 * OpenCart's logger with a level prefix.
 *
 * Only `warning` and `info` are used by the connector â€” the rare error case
 * (e.g., misconfigured at fail-hard) becomes a thrown exception, not a log.
 */
interface LoggerInterface
{
    /** @param array<string, scalar|null> $context */
    public function info(string $message, array $context = []): void;

    /** @param array<string, scalar|null> $context */
    public function warning(string $message, array $context = []): void;
}
