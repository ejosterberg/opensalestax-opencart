# Phase 01 — v0.1.0-alpha.1 — Bootstrap + tax extension + SSRF defense

> **Status:** drafted 2026-05-13. Owns the alpha ship.

## Goal

Ship a working OpenCart 4.x extension that, on US/USD checkouts, replaces OpenCart's geo-zone-based tax with destination-based rates from a merchant-self-hosted OpenSalesTax engine. Distribute via a `.ocmod.zip` installable from the admin UI.

## User stories

1. As an OpenCart merchant, I upload `opensalestax-opencart-v0.1.0-alpha.1.ocmod.zip` via **Admin → Extensions → Installer**, then enable it under **Extensions → Extensions → Order Totals**.
2. I open the extension's settings page, enter my engine base URL (e.g., `https://ost.example.com`), optionally an API key, choose fail-soft vs fail-hard, click **Save**, and the form rejects malformed or private-network URLs (unless I opt-in to private nets).
3. A US-shipping customer with a USD cart sees tax computed from my engine — itemized per-jurisdiction is desirable but not required for v0.1.
4. A non-US-shipping customer or a non-USD cart sees OpenCart's built-in tax — the extension is silent.
5. If my engine is unreachable, fail-soft mode falls through to OpenCart's tax tables; fail-hard mode displays an error and refuses to compute totals.

## In scope (v0.1.0)

- OpenCart 4.x (PHP 8.0+) is the primary target. 4.0.x and 4.1.x.
- `.ocmod.zip` package buildable via `tools/build-ocmod.sh`
- Order-total extension (`extension/total/opensalestax`) that calls the engine when the gate passes
- Admin settings page (controller + model + view + language file)
- SSRF-defending URL validator (RFC1918 / loopback / link-local / CGNAT / multicast rejected by default; opt-in toggle)
- SDK client factory wrapping `ejosterberg/opensalestax` (^0.1)
- Per-ZIP cache via OpenCart's cache layer with a 24h TTL
- Currency gate (USD), country gate (US), ZIP normalization (5-digit)
- Fail-soft (default) / fail-hard (opt-in) policy
- Structured logging via OpenCart's log facility
- Apache 2.0 + DCO sign-off + SPDX headers everywhere
- 30+ PHPUnit tests, PHPStan max, PHP-CS-Fixer PSR-12 + risky, composer audit clean
- SonarQube quality gate 0 BLOCKER / 0 CRITICAL / 0 BUGS / 0 VULNERABILITIES / 0 CODE SMELLS / 0 SECURITY HOTSPOTS
- `docs/SECURITY-REVIEW.md` with ≥10 reviewed threats
- README with the calculation-only disclaimer above the fold

## Out of scope for v0.1 (deferred)

- **OpenCart 3.x backport** — documented in `decisions/002-opencart-3x-compatibility.md` as v0.2 work. OC 3.x has a different extension model (OCMOD/vqmod) and the tax-extension hook point differs.
- Customer-group tax exemptions
- Multi-store (one OpenCart install hosting multiple stores)
- Live shipping-tax computation — we compute item tax only; shipping tax in v0.1 is whatever OpenCart computes (typically zero or geo-zone-based)
- Live integration test in CI — CI exercises unit tests only; the orchestrator agent runs the live test against VM 919
- Marketplace submission — v0.2

## Success criteria

- Package builds: `tools/build-ocmod.sh` produces `dist/opensalestax-opencart-vX.Y.Z.ocmod.zip`
- Unit tests: ≥30 cases pass under PHP 8.1 + 8.2 + 8.3
- PHPStan max: no errors
- Style: `php-cs-fixer fix --dry-run --diff` clean
- composer audit: 0 vulnerabilities
- SonarQube: 0 BLOCKER / 0 CRITICAL across BUGS / VULNERABILITIES / CODE SMELLS / SECURITY HOTSPOTS
- Smoke test (`docs/INTEGRATION-CHECK.md`): the smoke-test script hits `10.32.161.126:8080` and returns a non-zero tax for ZIP 55401 / $100 / general

## Open-question resolutions (locked here)

| Question | Resolution | Rationale |
|---|---|---|
| OC 3.x or 4.x for v0.1? | **OC 4.x** (4.0.x + 4.1.x). 3.x deferred to v0.2. | OC 4.x is the current major; new merchants land there. 3.x has a different extension model (OCMOD/vqmod) — separate phase. |
| OCMOD or pure event system? | **Order-total extension + event hook on cart.before**. No OCMOD modification XML. | OC 4.x deprecated the OCMOD modification engine; events are the supported seam. The order-total type is the documented seam for tax providers. |
| `.ocmod.zip` layout | OC 4.x format: zip contains `install.json` at root + `upload/` dir mirroring the OC tree. | Matches OC 4.x Extension Installer expectations. OC 3.x used `install.xml`; we emit OC 4.x format. |
| PHP version target | `^8.0` per `require` (OC 4.x baseline). CI matrix 8.1 / 8.2 / 8.3. | OC 4.0 baseline is 8.0; we don't go below SDK's `^8.2` for the dev environment but the runtime constraint matches OC 4.x. |
| Tax extension hook | `extension/total/opensalestax` (order-total type) + admin settings page. Reads cart products via `$this->cart->getProducts()`. | Order-total extensions are first-class in OC 4.x and run during cart-total computation. |
| Composer / PSR-4 inside extension | Bundle a `vendor/` directory inside `upload/system/library/opensalestax/vendor/` produced at build time. | OpenCart has no PSR-4 autoloader of its own; we ship a self-contained `composer install --no-dev` tree inside the extension. |

## Compatibility matrix

| OpenCart | PHP | Status |
|---|---|---|
| 4.0.x | 8.0, 8.1, 8.2, 8.3 | Target |
| 4.1.x | 8.1, 8.2, 8.3 | Target |
| 3.0.x | 7.4, 8.0 | v0.2 |
| 2.x | — | Not supported |

## Disclaimer (must appear)

> Tax calculations are provided as-is for convenience. The merchant is solely responsible for tax-collection accuracy and remittance to the appropriate jurisdictions. Verify against your state Department of Revenue before remitting.
