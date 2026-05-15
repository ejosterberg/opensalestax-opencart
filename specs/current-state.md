# Current state — opensalestax-opencart

> Snapshot updated 2026-05-14. Refresh after each phase ships.

## Shipped

| Phase | Version | Ship date | Notes |
|---|---|---|---|
| 01 — Bootstrap + tax extension + SSRF defense | v0.1.0-alpha.1 | 2026-05-13 | First public tag. Installable .ocmod.zip; unit tests green; SonarQube 0/0/0/0; live-storefront integration test pending. |
| 02 — Operational polish | v0.1.1 | 2026-05-14 | Cart-signature cache key; customer-group exemptions; admin "Test Connection" button. 91 tests / 193 assertions; PHPStan max + PHP-CS-Fixer + composer audit clean. |
| 03 — Surface + security | v0.2.0 | 2026-05-14 | Per-jurisdiction tax-line surface (opt-in); cURL IP-pinning closes the DNS-rebinding finding. 106 tests / 227 assertions; PHPStan max + PHP-CS-Fixer + composer audit clean. |

## Code shape (post-Phase 01)

- **Framework-agnostic core** in `src/`, PSR-4 autoloaded under `OpenSalesTax\OpenCart\` — `Support/` (ConfigBag, UrlValidator, ZipExtractor, CartPayloadBuilder, OpenSalesTaxClientFactory, RateCache, TaxCalculator, port interfaces) + `Exceptions/`.
- **OpenCart 4.x glue** in `extension/upload/` — admin controller + model + view + language; catalog model + language; `system/library/opensalestax/` bootstrap + adapters.
- **SDK consumed via Composer** (`ejosterberg/opensalestax ^0.1`); bundled at build time into the .ocmod.zip via `tools/build-ocmod.sh`.

## Quality bar (as of v0.2.0)

| Gate | Value |
|---|---|
| PHPUnit tests | 106 cases / 227 assertions, all green |
| PHPStan | level `max`, no errors |
| PHP-CS-Fixer | PSR-12 + risky, clean |
| `composer audit` | 0 vulnerabilities |
| SonarQube | 0 BLOCKER / 0 CRITICAL / 0 BUGS / 0 VULNERABILITIES / 0 CODE SMELLS / 0 SECURITY HOTSPOTS (re-scan pending after v0.2.0 ship) |
| Security review threats documented | ≥ 10 in `docs/SECURITY-REVIEW.md`; DNS-rebinding now **Mitigated** |

## Open phases / planned work

Source: README roadmap + Phase 01 plan "v0.2 candidates" + CHANGELOG "Known limitations". See `handoff.md` for the next slice to execute.

| Phase | Slice | Source roadmap line |
|---|---|---|
| 04 — OC 3.x backport | Different extension model (OCMOD/vqmod) | README v0.3 |
| 05 — Shipping-tax integration | Engine returns shipping tax; we surface it | README v0.3 |
| 06 — Multi-store support | Settings per OC store row | README v0.3 |
| 07 — Marketplace submission | Listing prep, screenshots, OC marketplace forms | README v0.1.x |
| Live-storefront integration test | Orthogonal — can land alongside any phase | not gated |

Live-storefront integration test against a real OpenCart 4.x install (`docs/INTEGRATION-CHECK.md`) is still pending across all phases. Until that's done, the connector's status is "unit-tested, not yet validated on a real cart."

## Non-negotiables (recap — see `constitution.md` for full text)

- Apache-2.0 + DCO sign-off + SPDX header on every source file
- No AI co-author trailers in commits
- US-shipping + USD-only — pass everything else through to OpenCart
- Fail-soft default; merchant opts into fail-hard
- Never log PII or secrets
- Calculation-only disclaimer wherever tax is surfaced
