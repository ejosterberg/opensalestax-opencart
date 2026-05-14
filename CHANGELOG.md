# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Pre-release identifiers (`-alpha.N`, `-rc.N`) signal that the listed version is not yet stable.

## [0.1.0-alpha.1] - 2026-05-13

### Added

- Initial OpenCart 4.x extension scaffold (admin settings page + catalog order-total entry point).
- `OpenSalesTax\OpenCart\Support\TaxCalculator` — the framework-agnostic pipeline (gate → cache → SDK → result).
- SSRF-defending `UrlValidator` rejecting RFC1918 / loopback / link-local / CGNAT / multicast by default; opt-in admin toggle for private-LAN engines.
- Per-ZIP rate caching via OpenCart's cache layer (24h default TTL).
- Currency gate (USD), country gate (US), ZIP-5 normalization, empty-cart bail.
- Fail-soft (default) / fail-hard policy on engine errors and configuration faults.
- Structured logging via OpenCart's `\Log` (numeric metadata only — no PII).
- Apache 2.0 license, DCO sign-off enforced in CI, SPDX header on every source file.
- 74 PHPUnit unit tests (153 assertions) covering gates, happy paths, fail-soft, cache hit / miss, SSRF defense, and config coercion.
- PHPStan level `max` clean, PHP-CS-Fixer (PSR-12 + risky) clean, `composer audit` clean.
- `tools/build-ocmod.sh` produces a self-contained `.ocmod.zip` artifact (bundles `vendor/` from a production `composer install`).
- `tools/smoke-test.php` round-trips a sample cart against the live engine.
- Calculation-only disclaimer rendered in the admin settings page above the form.
- README compatibility matrix (OpenCart 4.x targeted; 3.x deferred to v0.2).
- `docs/SECURITY-REVIEW.md` documenting the threat model (≥ 10 reviewed concerns).
- `docs/INTEGRATION-CHECK.md` documenting the smoke-test procedure.

### Known limitations (deferred to v0.2)

- OpenCart 3.x compatibility (OCMOD modification path).
- Per-jurisdiction tax-line surface (cart UI shows a single "Sales Tax" line in v0.1).
- DNS-rebinding IP-pinning (the SSRF defense validates at save time only).
- Customer-group exemptions.
- Shipping-tax integration.

[0.1.0-alpha.1]: https://github.com/ejosterberg/opensalestax-opencart/releases/tag/v0.1.0-alpha.1
