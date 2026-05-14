<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use OpenSalesTax\OpenCart\Support\LoggerInterface;

/**
 * Forwards `LoggerInterface` calls to OpenCart 4.x's `\Log`.
 *
 * OpenCart's `Log::write($message)` takes a single string. We render the
 * context as a `key=value` suffix so structured fields stay grep-able in
 * `system/storage/logs/error.log`.
 *
 * Customer addresses and full request payloads are NEVER logged — context
 * keys at call sites are restricted to numeric metadata (status, RTT,
 * line_count, fail_hard) and the 5-digit ZIP.
 */
final class OpenCartLoggerAdapter implements LoggerInterface
{
    /** @param object $log OpenCart's `\Opencart\System\Library\Log`. */
    public function __construct(private readonly object $log)
    {
    }

    public function info(string $message, array $context = []): void
    {
        $this->emit('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->emit('WARN', $message, $context);
    }

    /** @param array<string, scalar|null> $context */
    private function emit(string $level, string $message, array $context): void
    {
        if (!method_exists($this->log, 'write')) {
            return;
        }
        $rendered = $level . ' ' . $message;
        if ($context !== []) {
            $parts = [];
            foreach ($context as $key => $value) {
                $parts[] = $key . '=' . self::renderScalar($value);
            }
            $rendered .= ' ' . implode(' ', $parts);
        }
        $this->log->write($rendered);
    }

    private static function renderScalar(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return (string) $value;
    }
}
