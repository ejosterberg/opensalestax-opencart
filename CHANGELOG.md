# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Pre-release identifiers (`-alpha.N`, `-rc.N`) signal that the listed version is not yet stable.

## [0.2.1] - 2026-05-15

### Fixed

- **`JurisdictionSummary` post-round drift absorber.** The v0.2 absorber compared the engine's authoritative `tax_total` against the *unrounded* per-jurisdiction sum, then rounded each bucket. Per-bucket `round_half_up` on a `.x75` jurisdiction (e.g. MN state 6.875) injected +0.005 of phantom tax that the absorber never saw, leaving the visible per-jurisdiction sum off by 1¢ from the engine total in real-world MN cart flows. Drift is now computed against the rounded-bucket sum so the last bucket carries the residual and the displayed aggregate always ties exactly to the engine's `tax_total`. Surfaced by the VM 919 live-engine integration test ($100 MN/55401 cart, `tax_total=9.025` — naive code rendered 9.03).
- Regression test added covering the 6-jurisdiction MN fixture; 107 tests / 229 assertions total (was 106 / 227).

## [0.2.0] - 2026-05-14

### Added

- **Per-jurisdiction tax-line surface (opt-in).** New admin toggle "Show tax breakdown per jurisdiction"; when on, the cart shows one OpenCart total line per jurisdiction (state / county / city / special) — each labeled, distinct `code` (`opensalestax_state` / `_county` / `_city` / `_special`), sort_order = base + offset (state +0 ... special +3). Rounding drift between per-jurisdiction sums and the engine's authoritative `tax_total` is absorbed into the last summary so the cart total ties exactly to the engine's number. Default off — v0.1.1 single-line behavior is preserved.
- **DNS-rebinding defense via cURL IP-pinning.** `UrlValidator::validateAndResolve()` captures the resolved IP at save/validation time; `OpenSalesTaxClientFactory` plumbs that IP into Guzzle's `curl.options[CURLOPT_RESOLVE]` so the runtime cURL connection opens the TCP socket to the pinned IP regardless of what DNS returns at request time. TLS SNI + cert validation continue to use the hostname; only the underlying resolution is pinned. Closes the v0.1 `docs/SECURITY-REVIEW.md` finding "DNS rebinding (deferred to v0.2)".
- New `src/Support/JurisdictionSummary` value object grouping `CalculatedLine.jurisdictions[]` across cart lines, with rounding-drift absorption.
- 15 additional PHPUnit cases covering the per-jurisdiction grouping + sort logic, the IP-pinning directive shape, and the new `validateAndResolve()` path (106 tests / 227 assertions total).

### Changed

- `UrlValidator::validate(string $url): void` is now a backwards-compatible wrapper around the new `validateAndResolve(string $url): array{0:string,1:string}`. Save-time admin validation behavior is unchanged.
- `OpenSalesTaxClientFactory::make()` builds Guzzle with cURL options when the cURL extension is available; falls back to an un-pinned client with a logged warning when cURL is unavailable. Pinning is defense-in-depth on top of the save-time SSRF check.
- `TaxCalculator` exposes `getConfig()` so the catalog order-total glue can read the per-jurisdiction setting without re-reading `oc_setting`.

### Security

- DNS-rebinding finding moves from "Open / acceptable for v0.1" to **Mitigated**. See `docs/SECURITY-REVIEW.md`.

### Notes

- Per-jurisdiction surface is opt-in (default off). Merchants who upgrade without changing the toggle see identical behavior to v0.1.1.
- IP-pinning is always-on. Merchants whose DNS isn't compromised see no behavioral change; the cURL handle just opens to the pre-resolved IP instead of re-resolving.

## [0.1.1] - 2026-05-14

### Added

- **Cart-signature cache key.** Per-ZIP rate-cache entries now key off a deterministic SHA-256 prefix of the cart's `(category, amount)` tuples, so two carts at the same ZIP with different category mixes no longer share a cached engine response. Order-independent; legacy key shape (`ost:rate:{zip5}`) still produced when no signature is supplied.
- **Customer-group tax exemptions.** New admin field "Exempt customer groups" (comma-separated OpenCart customer-group IDs). Logged-in customers whose group matches bypass real-time tax calculation entirely — OpenCart's built-in tax flow handles them. Typical use: B2B / wholesale / nonprofit groups already mapped to OpenCart's tax classes.
- **Admin "Test Connection" button.** New diagnostic action on the settings page that builds the SDK client from the current form values, calls the engine's `/v1/health`, and reports back as JSON. Lets merchants validate the URL / API key / TLS / private-net configuration without putting a cart together. ACL-gated to `modify` permission on the route, so it cannot be used as an SSRF probe by lower-privileged users.
- 17 additional PHPUnit cases covering cart-signature determinism + isolation, exemption short-circuit semantics, and the CSV ID-list parser (91 tests / 193 assertions total).

### Changed

- `CartPayloadBuilder::build()` now returns a 3-tuple `[Address, LineItem[], string $signature]`. Internal change — the only caller is `TaxCalculator`.
- `RateCache::keyFor()` and `RateCache::remember()` accept an optional cart signature. When omitted, behavior is identical to v0.1.0.
- `TaxCalculator::calculate()` accepts an optional `customerGroupId` parameter. When omitted (or `null`), behavior is identical to v0.1.0.

### Notes

- **Cache migration:** entries written by v0.1.0 (`ost:rate:{zip5}`) become unreachable after upgrade because the new code reads from `ost:rate:{zip5}:{sig}`. They expire on the original TTL (default 24h). No migration code; traffic warms the new keys within a day.
- The calculation-only disclaimer and SSRF-defending URL validation remain unchanged from v0.1.0.

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

[0.2.0]: https://github.com/ejosterberg/opensalestax-opencart/releases/tag/v0.2.0
[0.1.1]: https://github.com/ejosterberg/opensalestax-opencart/releases/tag/v0.1.1
[0.1.0-alpha.1]: https://github.com/ejosterberg/opensalestax-opencart/releases/tag/v0.1.0-alpha.1
