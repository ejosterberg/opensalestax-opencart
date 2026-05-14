# Phase 01 — Plan

## File-level architecture

```
opensalestax-opencart/
├── composer.json                       # PSR-4 root for src/Support testable units
├── phpunit.xml.dist
├── phpstan.neon
├── .php-cs-fixer.dist.php
├── sonar-project.properties
├── README.md / CHANGELOG.md / SECURITY.md / CONTRIBUTING.md / LICENSE
│
├── src/                                # Framework-agnostic, PSR-4 autoloaded units
│   ├── Support/
│   │   ├── UrlValidator.php            # SSRF-defending URL validator
│   │   ├── ZipExtractor.php            # Customer-controlled ZIP → 5-digit normalize
│   │   ├── CartPayloadBuilder.php      # OC product list → SDK Address+LineItem[]
│   │   ├── OpenSalesTaxClientFactory.php  # Builds OpenSalesTax\Client from config bag
│   │   ├── RateCache.php               # Cache wrapper (interface-driven, OC cache adapter is thin)
│   │   ├── OpenCartCacheAdapter.php    # CacheRepositoryInterface impl backed by OC's $cache
│   │   ├── CacheRepositoryInterface.php   # Tiny port so unit tests don't need OC
│   │   ├── LoggerInterface.php         # Tiny port (psr-3-shaped) so unit tests don't need OC
│   │   ├── OpenCartLoggerAdapter.php   # LoggerInterface impl backed by OC's \Log
│   │   ├── TaxCalculator.php           # Top-level coordinator: gate → cache → engine → result
│   │   └── ConfigBag.php               # Frozen DTO of admin-panel settings
│   └── Exceptions/
│       ├── OpenCartOpenSalesTaxException.php
│       └── ConfigurationException.php
│
├── extension/                          # The .ocmod.zip source tree
│   ├── install.json                    # OpenCart 4.x extension manifest
│   └── upload/
│       ├── admin/
│       │   ├── controller/extension/opensalestax/total/opensalestax.php
│       │   ├── model/extension/opensalestax/total/opensalestax.php
│       │   ├── view/template/extension/opensalestax/total/opensalestax.twig
│       │   └── language/en-gb/extension/opensalestax/total/opensalestax.php
│       ├── catalog/
│       │   ├── controller/extension/opensalestax/total/opensalestax.php   # Optional event handler
│       │   ├── model/extension/opensalestax/total/opensalestax.php        # The OC4 "getTotal()" entry point
│       │   └── language/en-gb/extension/opensalestax/total/opensalestax.php
│       └── system/library/opensalestax/
│           ├── bootstrap.php           # require autoload + factory; reads OC Config; returns TaxCalculator
│           └── vendor/                 # composer install --no-dev (built artifact)
│
├── tests/Unit/
│   ├── Support/
│   │   ├── UrlValidatorTest.php
│   │   ├── ZipExtractorTest.php
│   │   ├── CartPayloadBuilderTest.php
│   │   ├── OpenSalesTaxClientFactoryTest.php
│   │   ├── RateCacheTest.php
│   │   ├── TaxCalculatorTest.php
│   │   └── ConfigBagTest.php
│   └── Stubs/
│       └── (in-memory cache + array-logger doubles)
│
├── docs/
│   ├── SECURITY-REVIEW.md
│   └── INTEGRATION-CHECK.md
│
└── tools/
    ├── build-ocmod.sh                  # Produces dist/opensalestax-opencart-vX.Y.Z.ocmod.zip
    └── smoke-test.php                  # CLI: hits engine at 10.32.161.126:8080 with ZIP 55401
```

## Why the `src/` / `extension/` split

OpenCart's extension layout is required by the platform — controllers must live under `admin/controller/...`, models under `admin/model/...`, etc. None of that is unit-testable in isolation: it requires OpenCart's `Registry`, `Config`, `Cart`, and Twig template stack at runtime.

So we split:

- **Framework-agnostic logic** lives in `src/`, PSR-4 autoloaded. Has no `require_once 'opencart/...'`. Tested standalone with PHPUnit.
- **OpenCart-shaped glue** lives in `extension/upload/`. Each glue file is the thinnest possible adapter — pull values out of `$this->config`, hand to `src/Support/TaxCalculator`, push results back.

This is the same split used by `opensalestax-bagisto` (testable `src/`, thin Laravel package bindings).

## The engine call seam

OpenCart 4.x's order-total extension API exposes a model with `getTotal(&$total_data, &$total, &$taxes)`. It receives the cart's running totals array and the per-tax-class accumulator. We:

1. Read `$this->cart->getProducts()` for line amounts + tax classes.
2. Read the customer shipping address via `$this->session->data['shipping_address']` or `$this->customer->...`.
3. Apply gates: configured? US? USD?
4. Build SDK `Address` + `LineItem[]`.
5. Hit cache; on miss, call SDK `calculate()`.
6. Replace `$taxes` entries for our touched tax classes with the engine result, and adjust `$total`.
7. On gate fail or engine error: bail (fail-soft) or throw (fail-hard).

We do NOT touch shipping tax in v0.1; OpenCart's shipping-tax logic flows unchanged. Documented in spec.md "Out of scope."

## ConfigBag (admin settings) shape

| Field | Type | Default | Admin label |
|---|---|---|---|
| status | bool | false | Enabled |
| base_url | string | '' | OpenSalesTax engine URL |
| api_key | string | '' | API key (optional) |
| timeout_seconds | float | 10.0 | HTTP timeout (seconds) |
| tls_verify | bool | true | Verify TLS certificate |
| allow_private_nets | bool | false | Allow private-network engines (advanced) |
| fail_hard | bool | false | Block checkout on engine error |
| cache_ttl_seconds | int | 86400 | Cache TTL (seconds) |

Stored under OpenCart's `setting` table at `module_opensalestax_*` keys.

## Cache key

`ost:rate:{zip5}` — 24h TTL by default. Tied to ZIP because the engine's response varies per ZIP. Cart contents shape the response too (line categories), but for v0.1 we cache by ZIP only and accept the trade-off (re-fetch on every category mix; could be tightened to `{zip5}:{cart_signature}` in v0.2).

## Threat model

- **SSRF via admin-controlled base_url** → `UrlValidator` rejects RFC1918 / loopback / link-local / CGNAT / multicast by default. Opt-in for legitimate private-LAN engines.
- **Customer-controlled ZIP** → `ZipExtractor` extracts the first 5 contiguous digits, regex-validated, before reaching the SDK.
- **Engine response trust** → engine is self-hosted; merchant trusts their own infra. Documented as a Low / by-design finding.
- **PII in logs** → log structured metadata only (status, RTT, line count, ZIP-5). No addresses, no line totals, no API keys.
- **API key in plain config** → store under OC's `setting` table same as all other extensions. Documented as a Low finding (Laravel / OC standard pattern).
- **DNS rebinding** → v0.2 candidate. SSRF defense raises the bar; full IP-pinning at validation time deferred.

## Build-time vendor bundling

OpenCart has no Composer autoloader. We can't `require_once 'opensalestax/Client.php'` without one. So at build time `tools/build-ocmod.sh`:

1. `composer install --no-dev --optimize-autoloader` inside the build temp dir
2. Copies `vendor/` into `extension/upload/system/library/opensalestax/vendor/`
3. Zips `extension/install.json` + `extension/upload/` into `dist/opensalestax-opencart-vX.Y.Z.ocmod.zip`

`extension/upload/system/library/opensalestax/bootstrap.php` does:

```php
require_once __DIR__ . '/vendor/autoload.php';
```

…before any class is referenced. The runtime extension calls `bootstrap.php` once and gets the `TaxCalculator` back.

## CI

`.github/workflows/ci.yml` matrix on PHP 8.1 / 8.2 / 8.3:

1. composer install
2. vendor/bin/phpunit
3. vendor/bin/phpstan analyse
4. vendor/bin/php-cs-fixer fix --dry-run --diff
5. composer audit
6. DCO sign-off check (existing action)

## v0.2 candidates (deferred)

- OC 3.x backport (different extension model)
- Customer-group exemption
- Per-jurisdiction tax line surface (mirror Magento connector's behavior)
- Cart-signature cache key
- DNS rebinding IP-pinning à la Magento connector
- Marketplace listing
