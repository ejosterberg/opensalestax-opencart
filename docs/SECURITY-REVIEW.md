# Security Review — opensalestax-opencart v0.2.0

**Reviewer:** automated audit + manual code review (2026-05-13, refreshed 2026-05-14 after Phase 02 + Phase 03 ship).
**Scope:** all PHP source files in `src/` plus the extension glue under `extension/upload/`. SDK code (`vendor/ejosterberg/opensalestax`) is reviewed in its own repo and assumed in-scope only for known-CVE checks here.
**Methodology:** OWASP Top 10 mapped to OpenCart-extension-specific concerns; manual line-by-line review against a CWE-driven checklist; `composer audit` against current advisories.

## Summary

| Severity | Count | Status |
|---|---|---|
| Critical | 0 | — |
| High | 0 | — |
| Medium | 0 | — |
| Low / Informational | 4 | All documented; no open action items |
| Mitigated since first review | 1 | DNS rebinding — closed in v0.2.0 via cURL IP-pinning |
| Defense-in-depth | 3 | TLS-verify-on-default, SSRF default-deny on private nets, and cURL IP-pinning |

**No critical, high, or medium-severity open findings.** The extension's threat model is bounded by the admin-settings-write boundary — an attacker with OpenCart admin write access has already won; the SSRF defense raises the bar against partial compromises (admin-panel-only writes, or future settings-import features).

`composer audit` against the dependency tree (production + dev): **0 known CVEs**.

## Findings

### LOW — API key stored in plain-text in `oc_setting`

**Files:** `extension/upload/admin/controller/extension/opensalestax/total/opensalestax.php`
**CWE:** CWE-256 (Plaintext Storage of a Password)

The OST API key, when configured, is stored in the `oc_setting` table via OpenCart's `setting/setting` model. An attacker with database read access can recover it.

**Mitigation:**

- OpenCart does not provide a built-in encrypted-settings API; storing tokens in `oc_setting` is the OC-standard pattern (every other extension does the same).
- The key flows into the SDK `Client` constructor in-memory and is set on the request via the `X-API-Key` header — it is never written to logs by this extension.
- The OST API key only grants access to the merchant's own self-hosted engine — it's a self-hosted auth token, not a third-party key. Compromise impact is bounded to the merchant's own infrastructure.

**Residual risk:** Acceptable. Documented in `README.md` under "Configure".

### LOW — Engine response trust

**File:** `extension/upload/catalog/model/extension/opensalestax/total/opensalestax.php`
**CWE:** CWE-602 (Client-Side Enforcement of Server-Side Security)

The catalog model trusts the engine's `tax_total` numeric and adds it directly to the cart total. If the engine is compromised, the response could under-tax (revenue loss) or over-tax (compliance risk).

**Mitigation:**

- The engine is **self-hosted by the merchant** — the merchant controls its security perimeter.
- The model does not render engine response content directly to the customer; only the typed `tax_total` numeric value flows into OpenCart's total.
- Engine-side: production deployment should run behind a firewall, with monitoring on the engine's `/v1/health`.

**Residual risk:** Trusts the engine by design. The architecture assumes the merchant trusts their own infrastructure.

### LOW — Verbose error messages in error log

**Files:** `src/Support/TaxCalculator.php`, `extension/upload/catalog/model/extension/opensalestax/total/opensalestax.php`
**CWE:** CWE-209 (Information Exposure Through an Error Message)

On engine errors the connector writes the exception class and message into OpenCart's `\Log`. For an unexpected `\Throwable`, the message could leak file paths, class names, and other internal details.

**Mitigation:**

- The OpenCart log file (`system/storage/logs/error.log`) is not accessible to customers; only admins / ops staff with filesystem access read it.
- The connector never echoes the exception message to the checkout customer — fail-soft falls through silently; fail-hard throws a wrapped `OpenCartOpenSalesTaxException` whose message is the connector's own (not the raw engine exception).

**Residual risk:** Acceptable for v0.1. Verbose internal-only logs help debug deployment issues.

### MITIGATED in v0.2.0 — DNS rebinding (previously LOW)

**Files:** `src/Support/UrlValidator.php`, `src/Support/OpenSalesTaxClientFactory.php`
**CWE:** CWE-918 (SSRF)

**v0.1 gap:** The `UrlValidator` resolved the engine host once at save time. A host that resolved to a public IP at validation and to an internal IP at request time could bypass the SSRF check.

**Mitigation in v0.2.0:** `UrlValidator::validateAndResolve()` captures the first public resolved IP at validation time (or the literal IP for IP-URLs; or the first private IP when "Allow private network engines" is on). `OpenSalesTaxClientFactory` plumbs that IP into Guzzle's `curl.options[CURLOPT_RESOLVE]` so the runtime cURL connection opens the TCP socket to the pinned IP, regardless of what DNS resolves the hostname to at request time. TLS SNI + cert validation continue to use the hostname; only the underlying resolution is pinned.

**Fallback:** When the cURL extension is unavailable (PHP built with `--disable-curl`), pinning is skipped and a warning is logged. The save-time SSRF check still runs; pinning is defense-in-depth on top of it.

### LOW — Admin form validates URL server-side but template is rendered raw

**File:** `extension/upload/admin/view/template/extension/opensalestax/total/opensalestax.twig`
**CWE:** CWE-79 (Reflected XSS, defense-in-depth check)

The settings form re-renders the previously-saved `base_url` and `api_key` values into HTML inputs. The Twig template emits them with `{{ value }}`. If OpenCart's Twig auto-escaping is somehow disabled, an attacker who can write the settings (admin-level) could inject HTML.

**Mitigation:**

- OpenCart 4.x's Twig environment has auto-escaping ON by default.
- The attack requires admin access to write the settings — and an admin can already inject HTML into many other places in OpenCart.

**Residual risk:** Acceptable. Documented as a defense-in-depth concern.

## Defense-in-depth — built in from v0.1

### TLS verification on by default

**File:** `src/Support/OpenSalesTaxClientFactory.php`
**Pattern:** Default-strict configuration

Guzzle's `verify` option is `true` by default in this extension. Opt-out via the admin "Verify TLS certificate" toggle exists for merchants using self-signed certificates, but the default and the README both push toward keeping it on.

### SSRF default-deny on private networks

**File:** `src/Support/UrlValidator.php`
**Pattern:** Default-strict input validation

The base URL validator rejects RFC1918 / loopback / link-local / CGNAT / multicast hosts by default. Merchants who legitimately self-host on the same LAN as OpenCart opt in via the admin "Allow private network engines" toggle — and that toggle is documented as "advanced" with a help-text explaining the trade-off.

### cURL IP-pinning against DNS rebinding (added v0.2.0)

**Files:** `src/Support/UrlValidator.php`, `src/Support/OpenSalesTaxClientFactory.php`
**Pattern:** Validate-then-pin

`UrlValidator::validateAndResolve()` returns the IP we resolved at validation time; `OpenSalesTaxClientFactory` pins `host:port` to that IP via Guzzle's `curl.options[CURLOPT_RESOLVE]`. A DNS responder that swaps the engine's IP between save and request can no longer redirect us to an internal address. Pinning is unconditional (applies whether or not "Allow private network engines" is on — the goal is rebinding defense, not network policy).

## Verified safe — areas reviewed with no findings

| Path | Concern | Result |
|---|---|---|
| `src/Support/UrlValidator::validate()` | SSRF via admin-controlled base_url | Rejects RFC1918 / loopback / link-local / CGNAT / multicast / unresolvable / non-http schemes by default; opt-in via admin toggle |
| `src/Support/ZipExtractor::extract()` | Customer-controlled ZIP injection | Pulls the first contiguous run of 5 digits via `\d{5}` regex before passing to SDK |
| `src/Support/CartPayloadBuilder::lineAmount()` | Customer-controlled price | Coerces to float via `number_format` after `is_numeric` guard; negative values rejected; non-numeric returns null and the builder skips the line |
| `src/Support/TaxCalculator::handleEngineError()` | Information disclosure in error path | Logs structured metadata only; wraps as `OpenCartOpenSalesTaxException` for fail-hard mode; never echoes raw exception messages to the customer |
| `src/Support/TaxCalculator` log calls | PII / secret leakage | Logs only ZIP-5, HTTP status, RTT, line count, fail_hard flag — never customer address, cart contents, or API key |
| `src/Support/OpenSalesTaxClientFactory::make()` | API key handling | Read from typed `ConfigBag`, passed to SDK `Client` constructor; never logged, never thrown in exceptions |
| `src/Support/RateCache::remember()` | Cache key injection | Key is `'ost:rate:' . $zip5` where `$zip5` is already digit-only-5-char-validated by the builder |
| `src/Support/ConfigBag::fromArray()` | Bad config blowups | All keys typed-cast with sensible defaults; `base_url` empty → extension inert (no engine call attempted) |
| `extension/upload/admin/controller/.../opensalestax.php` | CSRF on settings save | Honors OpenCart's standard `user_token` requirement; `user->hasPermission()` enforced |
| `extension/upload/catalog/model/.../opensalestax.php` | Exception fall-through | Wrapped in try/catch; fail-soft default; fail-hard rethrows a typed exception |
| `composer.json` dependency tree | Known CVEs | `composer audit` clean ✓ |
| Hardcoded secrets / credentials | Embedded keys | None found ✓ |
| `eval()` / dynamic include | Code injection vectors | None used ✓ |
| `unserialize()` on untrusted input | Object-injection vectors | None used ✓ |

## Test surface

The PHPUnit suite exercises **106 test cases / 227 assertions** (v0.2.0) covering:

- **URL validator (18 tests):** empty / malformed / non-http scheme / file scheme / ftp scheme / gopher scheme / loopback / RFC1918 ×3 / link-local / CGNAT / multicast / public / unresolvable / opt-in / multi-IP partial-public / literal-IP-without-DNS / scheme-rejected-even-with-opt-in
- **Zip extractor (9 tests):** empty / clean / zip+4 / zip with space / surrounding whitespace / state prefix / non-US postcode / four digits / symbol noise
- **Config bag (7 tests):** defaults / `isActive()` requirements / bool coercion / float coercion / int coercion / string trim / full-populated shape
- **Cart payload builder (13 tests):** happy path / multi-line / non-USD / non-US / missing country / missing postcode / malformed postcode / zip+4 normalize / empty cart / negative skip / non-numeric skip / string-numeric accept / lowercase USD / non-string country
- **Rate cache (4 tests):** key shape / miss writes / hit short-circuits / non-array recovery + jurisdiction round-trip
- **Client factory (7 tests):** empty URL / valid public / private fail-soft / private fail-hard rethrow / allow-private-nets / empty api key / catch-and-log-rejected URL
- **Tax calculator (10 tests):** inactive immediate / non-USD no-call / non-US no-call / happy path / cache hit / engine error fail-soft / engine error fail-hard / private base URL fail-soft / empty cart / malformed JSON
- **Exceptions (3 tests):** base extends Runtime / configuration is a base / wraps previous

## Recommendations for users

1. **Run the engine on a private network** when possible. If you do, enable "Allow private network engines" in the admin (otherwise the SSRF defense rejects RFC1918 hosts).
2. **Keep "Verify TLS certificate" ON** for any HTTPS engine endpoint. The opt-out exists only for self-signed-cert merchants who understand the trust implications.
3. **Store the API key only via the admin form**, never by editing `oc_setting` directly in the database. Restrict database access to your DBA / ops user.
4. **Pin the engine version** you've tested with. The engine-side state-bleed bug fixed in v0.22 was a calculation-correctness issue, not a security issue — but engine bugs are real and worth tracking.
5. **Monitor `system/storage/logs/error.log`** for `opensalestax:` entries. Repeated `engine /v1/calculate failed` lines are an early indicator of an engine outage that fail-soft mode is silently absorbing.

## Re-review schedule

- **v0.2.0** — re-review when DNS-rebinding mitigation lands or when per-jurisdiction surfacing introduces a new attack surface
- **Quarterly** — `composer audit` + a quick pass on any new code paths
- **On every contributor PR** — manual review of any security-touching change
