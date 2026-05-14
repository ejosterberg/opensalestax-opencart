# Phase 01 — Tasks (execution order)

1. [x] Create repo skeleton + `composer.json` + tooling configs
2. [x] LICENSE / CHANGELOG / SECURITY / CONTRIBUTING / README scaffolds
3. [x] `src/Support/ConfigBag.php` + tests
4. [x] `src/Support/UrlValidator.php` + tests (SSRF rejection cases)
5. [x] `src/Support/ZipExtractor.php` + tests (customer ZIP normalization)
6. [x] `src/Support/CartPayloadBuilder.php` + tests (OC cart shape → SDK shape)
7. [x] `src/Support/OpenSalesTaxClientFactory.php` + tests
8. [x] `src/Support/CacheRepositoryInterface.php` + `src/Support/RateCache.php` + tests
9. [x] `src/Support/LoggerInterface.php` + adapters (interface only; OC adapter is glue)
10. [x] `src/Support/TaxCalculator.php` + tests (gate → cache → engine → result; fail-soft / fail-hard)
11. [x] `src/Exceptions/*` + tests
12. [x] `extension/install.json` + extension layout (admin + catalog controller / model / view / language)
13. [x] `extension/upload/system/library/opensalestax/bootstrap.php`
14. [x] `tools/build-ocmod.sh` — produces `.ocmod.zip`
15. [x] `tools/smoke-test.php` — CLI smoke against engine
16. [x] PHPStan max passes
17. [x] PHP-CS-Fixer (PSR-12 + risky) passes
18. [x] `composer audit` clean
19. [x] PHPUnit ≥30 tests green
20. [x] `docs/SECURITY-REVIEW.md` (≥10 threats)
21. [x] `docs/INTEGRATION-CHECK.md`
22. [x] SonarQube project created + scan 0/0/0/0
23. [x] First commit DCO-signed; `gh repo create --public`
24. [x] Tag v0.1.0-alpha.1; `gh release create --prerelease` with `.ocmod.zip` asset
25. [x] GitHub topics set
