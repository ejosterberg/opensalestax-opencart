# Phase 02 — v0.1.1 — Operational polish

> **Status:** drafted 2026-05-14. Three small improvements bundled into the first minor release after v0.1.0-alpha.1.

## Goal

Close three of the roadmap's lowest-risk improvements in a single tagged release:

1. **Cart-signature cache key** — eliminate the v0.1 known limitation that mixed-category carts can reuse a stale ZIP-only cached response.
2. **Customer-group exemptions** — let merchants exclude wholesale / nonprofit customer groups from real-time tax calc, deferring to OpenCart's geo-zone tables for them.
3. **Admin "Test Connection" button** — let merchants verify the engine URL/key without putting a sample cart together.

## User stories

1. As a merchant with a mixed-category catalog (general + clothing + food), I expect the cached response for ZIP `55401` to be specific to the actual line categories in the customer's cart — not whatever cart happened to populate the cache first.
2. As a merchant with B2B wholesale customers (OpenCart customer group "Wholesale"), I want to mark that group as exempt so OpenCart's existing tax-class logic handles them; logged-in wholesale customers see no OST line at checkout.
3. As a merchant configuring the extension for the first time, I want a **Test Connection** button on the settings page that calls my engine's `/v1/health` and tells me whether the URL/key are correct, before I touch a real checkout.

## In scope (v0.1.1)

- New `CartPayloadBuilder` output adds a deterministic SHA-256 signature of the `(category, amount)` line tuples
- `RateCache::keyFor()` accepts an optional cart signature; key shape becomes `ost:rate:{zip5}` (no signature) or `ost:rate:{zip5}:{sig8}` (signed)
- `TaxCalculator` passes the signature through on the cache lookup
- `ConfigBag` gets a new `exemptCustomerGroupIds: int[]` field, parsed from a comma-separated string in admin settings (e.g. `2,3,7`)
- `TaxCalculator::calculate()` accepts an optional `customerGroupId` and short-circuits to `null` when the group is in the exempt list
- Admin controller adds a `testConnection()` endpoint that builds the SDK client (reusing `OpenSalesTaxClientFactory`), calls `health()`, and returns `{ok: bool, message: string, version: string|null}` JSON
- Admin view adds a "Test Connection" button + a small inline result panel
- Language files updated with new entry/help labels
- README "Configure" table refreshed
- CHANGELOG `[Unreleased]` finalized

## Out of scope for v0.1.1 (deferred)

- Per-jurisdiction tax-line surface → Phase 03 (v0.2)
- DNS-rebinding IP-pinning → Phase 03 (v0.2)
- OC 3.x backport → Phase 04
- Shipping-tax integration → Phase 05
- Multi-store → Phase 06
- Marketplace submission → Phase 07
- Cache-key migration tool — none. The old key gives stale-but-functional reads for up to 24h post-upgrade, then the TTL expires.

## Success criteria

- **Cache key**: a cart with lines `[(general, 50.00), (clothing, 25.00)]` and the same cart with `[(general, 25.00), (clothing, 50.00)]` produce **different** cache keys (categories with different amounts differ); a cart with the lines reordered (`[(clothing, 25.00), (general, 50.00)]`) produces the **same** cache key as the first (signature is order-independent).
- **Exemptions**: merchant configures `exempt_customer_group_ids = "2,3"`; logged-in customer with `customer_group_id = 2` triggers `calculate()` to return `null` BEFORE any engine call; customer in group `1` (default) triggers normal flow.
- **Test connection**: admin clicks the button on a configured page; happy path returns `{ok: true, message: "Engine reachable", version: "<engine version>"}`; bad URL returns `{ok: false, message: "<validator error>"}`; unreachable engine returns `{ok: false, message: "<sdk error>"}`.
- **Tests**: ≥ 88 unit tests pass (74 baseline + ~14 new). PHPStan max clean. PHP-CS-Fixer clean. composer audit clean.
- **Backwards compat**: an admin who upgrades without changing settings sees identical behavior — no exemptions configured, the new cache key still computes correctly (empty signature degrades to the v0.1 key), the test-connection button is purely additive.

## Disclaimer compliance

The calculation-only disclaimer remains rendered above the settings form. No change to its placement or text.

## Open questions

None remaining — all three features have clear UX and well-defined integration points. See `plan.md` for the file-level architecture.
