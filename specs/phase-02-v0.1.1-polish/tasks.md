# Phase 02 — Tasks (execution order)

> **Status:** all tasks shipped 2026-05-14 in v0.1.1.

## Cart-signature cache key

1. [x] Update `CartPayloadBuilder` to compute signature; return 3-tuple `[Address, LineItem[], string]`
2. [x] Update `RateCache::keyFor()` + `remember()` to thread an optional signature
3. [x] Update `TaxCalculator::prepare()` + `calculate()` to use the signature
4. [x] Tests: `CartPayloadBuilderTest` cases (determinism, reorder-stable, category-aware, amount-aware)
5. [x] Tests: `RateCacheTest` cases (key shape with + without sig, sig isolation)
6. [x] Tests: `TaxCalculatorTest` case (different signatures → both engine calls)

## Customer-group exemptions

7. [x] Add `exemptCustomerGroupIds` to `ConfigBag` + comma-list parser + tests
8. [x] Add `$customerGroupId` arg to `TaxCalculator::calculate()` + exempt-list short-circuit + tests
9. [x] Catalog model reads `$this->customer->getGroupId()` (guarded) and threads it through
10. [x] Admin controller: whitelist new setting key in `index()` defaults
11. [x] Admin view: new text input row
12. [x] Admin language: new entry/help strings

## Admin "Test Connection" button

13. [x] Admin controller: new `testConnection()` action (ACL-gated, URL-validated, builds SDK `Client`, calls `health()`, returns JSON)
14. [x] Admin view: new button + result panel + inline fetch handler
15. [x] Admin language: new button/text strings

## Bootstrap glue

16. [x] `extension/upload/system/library/opensalestax/bootstrap.php` reads the new `exempt_customer_group_ids` setting key

## Quality gate + ship

17. [x] PHPStan max passes
18. [x] PHP-CS-Fixer clean
19. [x] `composer audit` clean
20. [x] PHPUnit 91 tests / 193 assertions pass (+17 cases vs v0.1.0-alpha.1 baseline)
21. [x] README "Configure" table updated
22. [x] CHANGELOG: new `[0.1.1]` section
23. [x] Update `specs/current-state.md` and `specs/handoff.md`
24. [ ] DCO-signed commit; tag v0.1.1 (executed in the next step)
