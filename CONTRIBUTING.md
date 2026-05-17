# Contributing

Thanks for considering a contribution to **opensalestax-opencart**.

## Ground rules

- **DCO sign-off required.** Every commit must be signed off with `git commit -s`. The Developer Certificate of Origin (DCO) is included by reference; see [`https://developercertificate.org/`](https://developercertificate.org/). The CI gate rejects unsigned commits.
- **No AI co-author trailers.** Don't add `Co-authored-by: Claude` or similar to commit messages. Per the umbrella program's constitution, this project credits its human maintainer only.
- **Dual-licensed Apache-2.0 OR GPL-2.0-or-later + SPDX header.** Every source file starts with `// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later` (or the language equivalent — `# SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later` for shell scripts, `{# SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later #}` for Twig).
- **Calculation-only.** This extension does not file or remit tax. Pull requests adding filing / remittance / address-validation features will be closed without merge. See `specs/constitution.md` §4.
- **HTTP API is the contract.** Never call OpenSalesTax engine internals — go through the `ejosterberg/opensalestax` SDK. If the SDK is missing a method you need, open an issue there first.

## Branch model

- `main` is always release-quality. Tagged releases come off `main`.
- Feature branches: `feat/<slug>`. Bug fixes: `fix/<slug>`. Spec-only changes: `spec/<slug>`.
- Open a PR against `main`; CI runs PHPUnit, PHPStan, PHP-CS-Fixer, `composer audit`, and the DCO check. All must pass.

## Quality gate (must pass before merge)

```bash
composer install
composer check       # phpunit + phpstan + php-cs-fixer + composer audit
```

For more granular runs:

```bash
composer test           # PHPUnit
composer stan           # PHPStan level max
composer cs             # PHP-CS-Fixer dry-run
composer cs-fix         # PHP-CS-Fixer apply fixes
composer audit          # CVE check
```

If you touched the `extension/upload/**` glue, also run the build:

```bash
tools/build-ocmod.sh
```

and verify the produced `.ocmod.zip` opens cleanly via OpenCart's admin Installer.

## Tests

- Every PR with code changes must add or update unit tests covering the change.
- The framework-agnostic units in `src/` MUST be 100% testable without OpenCart's runtime — if you need OC's `Registry`, `Config`, or `Cart`, that code belongs in `extension/upload/` instead, with the testable seam staying in `src/`.
- Tests live under `tests/Unit/` and follow the existing structure (`tests/Unit/Support/SomethingTest.php` for `src/Support/Something.php`).

## SonarQube

For maintainers: run a SonarQube scan after major changes:

```bash
"/c/Users/ejosterberg/Documents/GITprojects/TicketsCADFixes/sonar-scanner-temp/sonar-scanner-6.2.1.4610-windows-x64/bin/sonar-scanner.bat" \
    -Dsonar.projectKey=opensalestax-opencart \
    -Dsonar.host.url=http://10.32.161.205:9000 \
    -Dsonar.token=$SONAR_TOKEN
```

The quality gate requires:

| Metric | Threshold |
|---|---|
| BLOCKER | 0 |
| CRITICAL | 0 |
| Bugs | 0 |
| Vulnerabilities | 0 |
| Code Smells | 0 |
| Security Hotspots | 0 |

## Reporting bugs

Open an issue at [https://github.com/ejosterberg/opensalestax-opencart/issues](https://github.com/ejosterberg/opensalestax-opencart/issues). Include:

- OpenCart version + PHP version
- Engine version (read from `GET /v1/health`)
- A minimal reproducer (cart contents, shipping address, expected vs actual)
- The relevant `system/storage/logs/error.log` lines (redact any PII before posting)

## Reporting security issues

**Do NOT open a public issue for vulnerabilities.** See [`SECURITY.md`](SECURITY.md).
