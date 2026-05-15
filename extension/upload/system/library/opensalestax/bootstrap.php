<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

/**
 * Bootstraps the OpenSalesTax connector inside OpenCart 4.x.
 *
 * Called from the order-total model when tax computation runs. Loads the
 * bundled `vendor/autoload.php` (produced at build time by
 * `tools/build-ocmod.sh`), reads OpenCart's settings via the registry, and
 * returns a configured `TaxCalculator`.
 *
 * Returns null when the extension is inert (disabled or unconfigured).
 *
 * IMPORTANT: This file is the ONLY entry point from OpenCart into our PHP
 * SDK. Anything that throws here (autoload failure, OpenCart contract drift)
 * is caught by the caller and degrades to fail-soft — see
 * `catalog/model/extension/opensalestax/total/opensalestax.php`.
 *
 * The autoloads below are intentional: OpenCart core has no PSR-4 autoloader
 * accessible to extensions, so we wire up our bundled one here exactly once.
 * The SonarQube `php:S4833` "use namespace import" alternative does not apply
 * — there is no class loader registered with these classes before this file
 * runs.
 */

// phpcs:disable PSR1.Files.SideEffects
$ostaxLibBase = __DIR__;
require_once $ostaxLibBase . '/vendor/autoload.php'; // NOSONAR — bundled autoloader is the entry point
require_once $ostaxLibBase . '/OpenCartCacheAdapter.php'; // NOSONAR — adapter glue, no PSR-4 loader for it
require_once $ostaxLibBase . '/OpenCartLoggerAdapter.php'; // NOSONAR — adapter glue, no PSR-4 loader for it
unset($ostaxLibBase);
// phpcs:enable PSR1.Files.SideEffects

use OpenSalesTax\OpenCart\Support\CartPayloadBuilder;
use OpenSalesTax\OpenCart\Support\ConfigBag;
use OpenSalesTax\OpenCart\Support\OpenSalesTaxClientFactory;
use OpenSalesTax\OpenCart\Support\RateCache;
use OpenSalesTax\OpenCart\Support\TaxCalculator;

/**
 * Bootstrap helper class. Wraps the procedural startup code so each method
 * has a single responsibility and naming matches our coding standard.
 */
final class OpensalestaxBootstrap
{
    /**
     * Build the connector pipeline from OpenCart's runtime registry.
     *
     * @param object $registry OpenCart's `\Opencart\System\Engine\Registry`
     *                         (typed as object because we don't autoload OC core).
     */
    public static function build(object $registry): ?TaxCalculator
    {
        if (!method_exists($registry, 'get')) {
            return null;
        }

        $config = $registry->get('config');
        $cache  = $registry->get('cache');
        $log    = $registry->get('log');

        if (!is_object($config) || !is_object($cache) || !is_object($log)) {
            return null;
        }

        $bag = ConfigBag::fromArray(self::readSettings($config));

        $logger       = new \OpenCartLoggerAdapter($log);
        $cacheAdapter = new \OpenCartCacheAdapter($cache);

        return new TaxCalculator(
            config: $bag,
            clientFactory: new OpenSalesTaxClientFactory($logger),
            payloadBuilder: new CartPayloadBuilder(),
            cache: new RateCache($cacheAdapter, $bag->cacheTtlSeconds),
            logger: $logger,
        );
    }

    /**
     * Read the connector's settings from OpenCart's Config service.
     *
     * Settings are stored under the `module_opensalestax_*` prefix. We strip
     * the prefix here so the `ConfigBag::fromArray()` key names match what
     * the unit tests assert against.
     *
     * @param object $config OpenCart's `\Opencart\System\Engine\Config`.
     * @return array<string, mixed>
     */
    private static function readSettings(object $config): array
    {
        if (!method_exists($config, 'get')) {
            return [];
        }
        $keys = [
            'status',
            'base_url',
            'api_key',
            'timeout_seconds',
            'tls_verify',
            'allow_private_nets',
            'fail_hard',
            'cache_ttl_seconds',
            'exempt_customer_group_ids',
        ];
        $out = [];
        foreach ($keys as $key) {
            $value = $config->get('module_opensalestax_' . $key);
            if ($value !== null) {
                $out[$key] = $value;
            }
        }
        return $out;
    }
}
