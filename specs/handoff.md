# Handoff — opensalestax-opencart

> What the next Claude session should pick up first. Refresh at the end of every session.

## Pick up here

**Phase 03 (v0.2 — surface + security)** is the next slice. Spec lives at `specs/phase-03-v0.2-surface-security/`. Two bundled improvements:

1. **Per-jurisdiction tax-line surface** — instead of one "Sales Tax" total line, emit one OpenCart total line per resolved jurisdiction (state / county / city / special-district). Reuses `CalculatedLine.jurisdictions` from the SDK; touches the catalog model's `applyResponse()` and adds language strings. Optional admin toggle (default off) to preserve v0.1.1 behavior for merchants who like the single-line view.
2. **DNS-rebinding IP-pinning** — when the URL validator accepts a hostname, capture the resolved IP and pin the engine's HTTP client to that IP for the lifetime of the request. Closes the "validator validates at save time only" finding from `docs/SECURITY-REVIEW.md`.

## Ship checklist for Phase 03

- [ ] `specs/phase-03-v0.2-surface-security/spec.md` + `plan.md` + `tasks.md`
- [ ] All features implemented under `src/Support/`
- [ ] Test count delta: target +10 to +15 (per-jurisdiction grouping ~6, IP-pinning ~5)
- [ ] PHPStan max clean
- [ ] PHP-CS-Fixer clean
- [ ] composer audit clean
- [ ] `docs/SECURITY-REVIEW.md` updated: DNS-rebinding moves from "Open" to "Mitigated"
- [ ] CHANGELOG `[0.2.0]` section; README v0.2 Roadmap line tracked off
- [ ] Tag v0.2.0; release notes; .ocmod.zip artifact uploaded

## Deferred (do NOT pull into Phase 03)

- **OC 3.x backport** — Phase 04. Different extension model entirely (OCMOD/vqmod).
- **Shipping-tax integration** — Phase 05. Needs engine-side support for shipping cost as a line item.
- **Multi-store** — Phase 06. Per-store settings rows in `oc_setting`.
- **OpenCart Marketplace submission** — Phase 07. Paperwork + UX polish, not code.
- **Live-storefront integration test** — orthogonal; can land alongside any phase but isn't gated on any.

## Risks / things to watch (Phase 03)

- **Tax-line ordering**: OpenCart sorts totals by `sort_order`. Per-jurisdiction lines need distinct sort_orders or they'll render in arbitrary order. Use base + offset (e.g. base 5, +0/+1/+2 for state/county/city).
- **Tax-line code uniqueness**: OpenCart's order-totals are keyed by `code` in the totals array. Distinct jurisdictions need distinct codes (`opensalestax_state`, `opensalestax_county`, etc.) OR a single code with a structured title.
- **IP-pinning + TLS SNI**: pinning to an IP while keeping the URL's hostname for the TLS handshake is the correct shape. Guzzle's `curl.options.CURLOPT_RESOLVE` is the canonical mechanism. Don't strip the hostname from the URL — that breaks SNI + the engine's cert validation.
- **IP-pinning + private nets**: when `allow_private_nets` is on, IP-pinning should still apply (pin to the resolved private IP). The point is rebinding defense, not network policy.

## What v0.1.1 shipped

Just for context — see `specs/phase-02-v0.1.1-polish/` for the full record.

- Cart-signature cache key (`CartPayloadBuilder::signatureFor()`, `RateCache::keyFor($zip, $sig)`)
- Customer-group exemptions (`ConfigBag::$exemptCustomerGroupIds`, `TaxCalculator::calculate(..., $customerGroupId)`)
- Admin "Test Connection" button (controller `testConnection()` action + Twig button + fetch handler)
- 17 new PHPUnit cases; total 91 / 193 assertions
- PHPStan max + CS-Fixer + composer audit clean

## Last verified

- 2026-05-14 — Phase 02 shipped as v0.1.1. PHPUnit / PHPStan / CS-Fixer / composer audit all green. SonarQube re-scan pending (no expected regressions; new hashing is `sha256`, new parser is plain string ops).
