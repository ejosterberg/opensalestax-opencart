<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\OpenCart\Support;

/**
 * Frozen DTO of the connector's admin-panel settings.
 *
 * Built once per request by the extension glue layer from OpenCart's
 * `setting` table (or a default array in tests). All access is read-only â€”
 * the glue layer passes this around instead of letting consumers reach back
 * into OpenCart's config service.
 *
 * Defaults mirror the documented "safe" install: disabled, fail-soft,
 * TLS-on, private nets blocked.
 */
final readonly class ConfigBag
{
    /**
     * @param int[] $exemptCustomerGroupIds Sorted unique customer-group IDs that
     *     should bypass real-time tax calculation (OpenCart's built-in tax
     *     flow handles them instead â€” typically used for B2B / wholesale /
     *     nonprofit groups already configured under OpenCart's tax classes).
     * @param bool  $perJurisdictionLines  When true, the catalog order-total
     *     model emits one totals row per jurisdiction (state / county / city
     *     / special) instead of one aggregate "Sales Tax" line. Default off
     *     so v0.1 installs see no change after upgrade.
     * @param string[] $nexusStates Per-state nexus allowlist (CP-3, v0.3.0).
     *     Uppercase 2-letter US state codes. Empty array = filter disabled
     *     (engine called for every US/USD cart). Non-empty = engine only
     *     called when destination state is in this set. Missing /
     *     unresolvable destination state with the filter active is
     *     fail-closed (no engine call).
     */
    public function __construct(
        public bool $enabled,
        public string $baseUrl,
        public string $apiKey,
        public float $timeoutSeconds,
        public bool $tlsVerify,
        public bool $allowPrivateNets,
        public bool $failHard,
        public int $cacheTtlSeconds,
        public array $exemptCustomerGroupIds = [],
        public bool $perJurisdictionLines = false,
        public array $nexusStates = [],
    ) {
    }

    /**
     * Build from OpenCart's setting array (or any flat string map).
     *
     * Recognized keys (all `module_opensalestax_*` prefixed in OpenCart, but
     * accepted bare here so the unit tests don't need to mirror OC's prefix):
     *
     * @param array<string, mixed> $settings
     */
    public static function fromArray(array $settings): self
    {
        return new self(
            enabled: self::boolish($settings, 'status', false),
            baseUrl: self::stringish($settings, 'base_url', ''),
            apiKey: self::stringish($settings, 'api_key', ''),
            timeoutSeconds: self::floatish($settings, 'timeout_seconds', 10.0),
            tlsVerify: self::boolish($settings, 'tls_verify', true),
            allowPrivateNets: self::boolish($settings, 'allow_private_nets', false),
            failHard: self::boolish($settings, 'fail_hard', false),
            cacheTtlSeconds: self::intish($settings, 'cache_ttl_seconds', 86400),
            exemptCustomerGroupIds: self::intList($settings, 'exempt_customer_group_ids'),
            perJurisdictionLines: self::boolish($settings, 'per_jurisdiction_lines', false),
            nexusStates: self::stateList($settings, 'nexus_states'),
        );
    }

    /**
     * Parse a comma- or whitespace-separated list of US state codes into a
     * deduped uppercase array. Accepts arrays for programmatic injection.
     * Drops anything that isn't a 2-letter US state code silently.
     *
     * @param array<string, mixed> $bag
     * @return string[]
     */
    private static function stateList(array $bag, string $key): array
    {
        $raw = $bag[$key] ?? null;
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_array($raw)) {
            $candidates = $raw;
        } elseif (is_string($raw)) {
            $candidates = preg_split('/[\s,]+/', $raw) ?: [];
        } else {
            return [];
        }
        $out = [];
        foreach ($candidates as $candidate) {
            if (!is_string($candidate) && !is_int($candidate)) {
                continue;
            }
            $upper = strtoupper(trim((string) $candidate));
            if (preg_match('/^[A-Z]{2}$/', $upper) === 1 && !in_array($upper, $out, true)) {
                $out[] = $upper;
            }
        }
        return $out;
    }

    /**
     * True when the extension is ON and minimally configured (base_url set).
     * The glue layer uses this as a fast-path: if false, return control to
     * OpenCart's tax flow without further work.
     */
    public function isActive(): bool
    {
        return $this->enabled && $this->baseUrl !== '';
    }

    /** @param array<string, mixed> $bag */
    private static function boolish(array $bag, string $key, bool $default): bool
    {
        if (!isset($bag[$key])) {
            return $default;
        }
        $raw = $bag[$key];
        return match (true) {
            is_bool($raw)   => $raw,
            is_int($raw)    => $raw !== 0,
            is_string($raw) => in_array(strtolower(trim($raw)), ['1', 'true', 'on', 'yes'], true),
            default         => $default,
        };
    }

    /** @param array<string, mixed> $bag */
    private static function stringish(array $bag, string $key, string $default): string
    {
        $raw = $bag[$key] ?? null;
        return is_string($raw) ? trim($raw) : $default;
    }

    /** @param array<string, mixed> $bag */
    private static function floatish(array $bag, string $key, float $default): float
    {
        $raw = $bag[$key] ?? null;
        if (is_int($raw) || is_float($raw) || (is_string($raw) && is_numeric($raw))) {
            return (float) $raw;
        }
        return $default;
    }

    /** @param array<string, mixed> $bag */
    private static function intish(array $bag, string $key, int $default): int
    {
        $raw = $bag[$key] ?? null;
        if (is_int($raw) || is_float($raw) || (is_string($raw) && is_numeric($raw))) {
            return (int) $raw;
        }
        return $default;
    }

    /**
     * Parse a comma-separated string of integer IDs into a deduped, sorted
     * `int[]`. Accepts string `"2, 3, 7"` (merchant-typed input from the
     * admin form), array `[2, 3, 7]` (programmatic injection in tests), or
     * any value type â€” anything non-coercible drops out.
     *
     * `"0"` is a valid ID (OpenCart uses customer-group-id `0` for the
     * default "guest" group), so the zero filter checks "is it a digit run?"
     * rather than `!= 0`.
     *
     * @param array<string, mixed> $bag
     * @return int[]
     */
    private static function intList(array $bag, string $key): array
    {
        $raw = $bag[$key] ?? null;
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_array($raw)) {
            $candidates = $raw;
        } elseif (is_string($raw) || is_int($raw) || is_float($raw)) {
            $candidates = explode(',', (string) $raw);
        } else {
            return [];
        }

        $ids = [];
        foreach ($candidates as $candidate) {
            if (is_int($candidate)) {
                $ids[] = $candidate;
                continue;
            }
            if (!is_string($candidate) && !is_float($candidate)) {
                continue;
            }
            $trimmed = trim(is_float($candidate) ? (string) $candidate : $candidate);
            if ($trimmed === '' || preg_match('/^-?\d+$/', $trimmed) !== 1) {
                continue;
            }
            $ids[] = (int) $trimmed;
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        return $ids;
    }
}
