# Handoff — opensalestax-opencart

> What the next Claude session should pick up first. Refresh at the end of every session.

## Pick up here

**v0.2.1 shipped 2026-05-15** — JurisdictionSummary drift absorber now runs after the per-bucket round. Regression test covers the 9.025/6.875 MN fixture from the VM 919 integration check. Tag `v0.2.1`, GitHub release published.

**Check in with Eric before any larger phase.** Available phases:

- **Phase 04 (OC 3.x backport)** — entirely separate extension model (OCMOD/vqmod). Effectively a parallel codebase living in `extension-oc3/` with its own build script and a shared `src/Support/` library. Multi-day work.
- **Phase 05 (Shipping-tax integration)** — needs engine-side support for shipping cost as a line item (verify SDK + engine support first; may block on upstream).
- **Phase 06 (Multi-store)** — per-store settings keys in `oc_setting`. OpenCart 4.x has the `store_id` column; we'd add a setting-key prefix per store and a store-picker in the admin UI.
- **Phase 07 (Marketplace submission)** — paperwork + screenshots + the OpenCart Marketplace form. Not code.
- **Live-storefront integration test** — orthogonal. Can land alongside any phase. Needs a real OpenCart 4.x install + a reachable OST engine.

**Recommend:** ask Eric which of those is highest priority, OR shift to the live-storefront integration test to validate v0.2.0 against a real OpenCart install before any more code lands.

## What v0.2.0 shipped (recap)

See `specs/phase-03-v0.2-surface-security/` for the full record.

- Per-jurisdiction tax-line surface — opt-in via the new admin toggle.
- `JurisdictionSummary` value object grouping the engine's per-line jurisdiction breakdown across cart lines, with rounding-drift absorption.
- DNS-rebinding defense — `UrlValidator::validateAndResolve()` captures the resolved IP at validation; `OpenSalesTaxClientFactory` pins it through Guzzle's `curl.options[CURLOPT_RESOLVE]`.
- 15 new PHPUnit cases; total 106 / 227 assertions.
- SECURITY-REVIEW.md: DNS-rebinding finding moves Open → Mitigated.

## Still in-flight

Nothing in code — Phase 03 is complete. The remote isn't pushed (Eric explicitly asked Claude to not push without authorization); tags `v0.1.1` and `v0.2.0` exist locally on the worktree branch.

## Risks / things to watch (next phase, whichever it is)

- **OC 3.x backport**: don't try to share controllers — OC 3.x uses `controller/extension/total/opensalestax.php` (no nesting); OC 4.x uses `controller/extension/opensalestax/total/opensalestax.php`. Settings storage shape is the same; bootstrap shape differs.
- **Multi-store**: `oc_setting` has a `store_id` column already, but the admin form's `editSetting()` writes scoped by store. The connector's runtime read needs to respect the active store, not the default store.
- **Shipping-tax**: the engine's `/v1/calculate` accepts shipping as a line item (`category: shipping`), but verify the SDK exposes it cleanly. If the SDK lacks a shipping affordance, file upstream first.

## Last verified

- 2026-05-14 — Phase 03 shipped as v0.2.0 locally. PHPUnit 106/227, PHPStan max, PHP-CS-Fixer, composer audit all green. Tags `v0.1.1` and `v0.2.0` exist locally on branch `claude/serene-blackwell-65567f`. Not pushed to remote.
