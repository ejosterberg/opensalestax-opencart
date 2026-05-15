# Phase 03 — Tasks (execution order)

> **Status:** all tasks shipped 2026-05-14 in v0.2.0.

## Per-jurisdiction surface

1. [x] Add `perJurisdictionLines` bool to `ConfigBag` + tests
2. [x] New `src/Support/JurisdictionSummary.php` + `JurisdictionSummaryTest`
3. [x] Update catalog order-total model: branch on flag, emit per-jurisdiction `$totals[]` when on
4. [x] Admin controller: whitelist new setting key
5. [x] Admin view: new select row
6. [x] Admin + catalog language: new entry/help/title strings
7. [x] Bootstrap: read new setting key

## DNS-rebinding IP-pinning

8. [x] New `UrlValidator::validateAndResolve()` returning `[$host, $pinnedIp]`; existing `validate()` becomes wrapper
9. [x] `OpenSalesTaxClientFactory::make()` plumbs pinned IP into Guzzle curl options; cURL-unavailable fallback logs and degrades
10. [x] Tests for both (UrlValidator new return shape; factory `buildResolveDirective` directive shape)

## Quality gate + ship

11. [x] PHPUnit 106 / 227 (vs 91 baseline; +15 cases)
12. [x] PHPStan max passes
13. [x] PHP-CS-Fixer clean
14. [x] composer audit clean
15. [x] SECURITY-REVIEW.md: DNS-rebinding entry moves Open → Mitigated
16. [x] CHANGELOG: new `[0.2.0]` section
17. [x] README "Configure" table updated (per-jurisdiction toggle)
18. [x] install.json version bumped to 0.2.0
19. [x] Update `specs/current-state.md` and `specs/handoff.md`
20. [ ] DCO-signed commit; tag v0.2.0 locally (next step)
