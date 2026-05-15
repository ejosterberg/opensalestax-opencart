# Phase 03 — v0.2.0 — Per-jurisdiction surface + DNS-rebinding defense

> **Status:** drafted 2026-05-14. Two improvements bundled into the first minor release after v0.1.1.

## Goal

Two roadmap items the security review + UI roadmap both flagged for v0.2:

1. **Per-jurisdiction tax-line surface** — instead of one "Sales Tax" order-total line, optionally emit one line per resolved jurisdiction (state / county / city / special-district). The engine already returns the breakdown in `CalculatedLine.jurisdictions`; we surface it.
2. **DNS-rebinding defense (IP-pinning)** — close the `docs/SECURITY-REVIEW.md` finding that says "URL validator runs at save time only." Capture the resolved IP at validation time and pin the HTTP client to that IP for the runtime request, so a malicious DNS responder cannot resolve `ost.example.com` to `8.8.8.8` at save time and `127.0.0.1` at request time.

## User stories

1. As a Minnesota merchant who collects state + county + city tax separately for accounting, I want my OpenCart cart to show each jurisdiction as its own line ("Minnesota State Tax", "Hennepin County Tax", "Minneapolis City Tax") so my bookkeeping reflects the breakdown.
2. As a merchant who likes the simple "Sales Tax: $8.83" line and doesn't want to alarm checkout shoppers with three lines, I want to keep the v0.1.1 behavior — a single aggregate line.
3. As a security-conscious merchant, I want the SSRF defense to keep working even if my DNS is compromised after I save the engine URL.

## In scope (v0.2.0)

### Per-jurisdiction surface

- New `ConfigBag` field `bool $perJurisdictionLines` (default `false`).
- Admin settings page: new dropdown "Show tax breakdown per jurisdiction" (Yes / No).
- Catalog order-total model: when the flag is on AND the engine response has > 0 jurisdictions, emit one `$totals[]` entry per unique `(name, type)` jurisdiction with summed tax across lines. Otherwise emit the single aggregate line (current behavior).
- Each per-jurisdiction line carries a distinct `code` (`opensalestax_state`, `opensalestax_county`, `opensalestax_city`, `opensalestax_special`) and `sort_order` = base + offset (state +0, county +1, city +2, special +3).
- Engine `tax_total` is the authoritative aggregate sum — when individual jurisdiction taxes don't quite sum to `tax_total` (rounding boundary), the last jurisdiction line absorbs the remainder.
- New `JurisdictionSummary` value object built from `CalculatedLine.jurisdictions[]` — keeps the catalog model thin.

### DNS-rebinding defense

- `UrlValidator::validate()` becomes `validateAndResolve()`: returns the chosen resolved IP (the first public one for hostname targets, or the IP itself for IP-literal URLs) alongside a clean parsed URL.
- `OpenSalesTaxClientFactory` uses Guzzle's `curl.options[CURLOPT_RESOLVE]` to pin `host:port` to the captured IP for the request. TLS SNI uses the URL's hostname; cert validation passes against the hostname.
- IP-pinning applies regardless of `allow_private_nets` — the goal is rebinding defense, not network policy.
- Backwards-compat shim: keep `validate()` as a thin wrapper that calls `validateAndResolve()` and discards the IP, so admin-form save-time validation keeps the same signature.

## Out of scope for v0.2.0 (deferred)

- OC 3.x backport → Phase 04
- Shipping-tax integration → Phase 05
- Multi-store → Phase 06
- Marketplace submission → Phase 07
- IPv6 pinning — `CURLOPT_RESOLVE` accepts only one IP per host:port row; we pick the first **public** v4 if available, else the first v6. Mixed-stack engines work transparently.
- Live integration test for the rebind defense — would require running a mock DNS responder; documented as a manual smoke step in `docs/INTEGRATION-CHECK.md`.

## Success criteria

- **Per-jurisdiction** off (default): identical output to v0.1.1 — single "Sales Tax" line.
- **Per-jurisdiction** on: a cart that the engine answers with `jurisdictions: [state, county]` produces exactly two order-total lines, each labeled and summing to the engine's `tax_total`.
- **IP-pinning**: a stub `UrlValidator` that returns `(parsed, '203.0.113.42')` produces a Guzzle client whose `curl.options[CURLOPT_RESOLVE]` array contains `ost.example.com:443:203.0.113.42` (or the appropriate port).
- **Validator backwards-compat**: `UrlValidator::validate('https://ost.example.com')` continues to return `void` and throw on rejection. The new `validateAndResolve()` is additive.
- **Tests**: 99+ unit cases pass (91 baseline + ~10 new). PHPStan max + PHP-CS-Fixer + composer audit clean.
- **SECURITY-REVIEW**: the DNS-rebinding entry moves from "Open" to "Mitigated".

## Disclaimer compliance

The per-jurisdiction surface is purely additive cosmetic data. The calculation-only disclaimer remains rendered above the admin settings form. No change to the disclaimer text or placement.

## Open questions

None. The per-jurisdiction behavior is opt-in (default off → no behavior change); the IP-pinning is purely additive at the network layer (no behavior change for merchants whose DNS isn't compromised).
