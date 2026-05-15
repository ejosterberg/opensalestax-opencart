# Phase 03 — Plan

## File-level changes

### 1. Per-jurisdiction tax-line surface

| File | Change |
|---|---|
| `src/Support/ConfigBag.php` | New readonly bool `$perJurisdictionLines`. Parsed from `per_jurisdiction_lines` setting via the existing `boolish` helper. |
| `src/Support/JurisdictionSummary.php` *(new)* | Value object holding `(name, type, taxAmount)` for one jurisdiction. Static factory `JurisdictionSummary::fromResponse(CalculateResponse)` returns the grouped list summed across cart lines. |
| `extension/upload/catalog/model/extension/opensalestax/total/opensalestax.php` | `applyResponse()` branches on `$bag->perJurisdictionLines`. When off → existing single-line behavior. When on → call `JurisdictionSummary::fromResponse()` and emit one `$totals[]` per summary; assign distinct `code`s + `sort_order` offsets. Aggregate `$total['total']` accumulates the engine's `tax_total` exactly (in case per-jurisdiction summing drifts a cent under rounding). |
| `extension/upload/admin/controller/extension/opensalestax/total/opensalestax.php` | Whitelist `module_opensalestax_per_jurisdiction_lines` in `index()` defaults. |
| `extension/upload/admin/view/template/extension/opensalestax/total/opensalestax.twig` | New select row "Show tax breakdown per jurisdiction" (Yes / No). |
| `extension/upload/admin/language/en-gb/extension/opensalestax/total/opensalestax.php` | `entry_per_jurisdiction_lines`, `help_per_jurisdiction_lines`. Plus title strings: `title_state_tax`, `title_county_tax`, `title_city_tax`, `title_special_tax`. |
| `extension/upload/catalog/language/en-gb/extension/opensalestax/total/opensalestax.php` | Same title strings — they show up on the catalog (cart) side. |
| `extension/upload/system/library/opensalestax/bootstrap.php` | Read the new `per_jurisdiction_lines` setting key. |
| `tests/Unit/Support/JurisdictionSummaryTest.php` *(new)* | Cases: single-jurisdiction passes through; multi-jurisdiction across multiple lines groups + sums; empty jurisdictions returns empty; rounding-aware accumulation. |
| `tests/Unit/Support/ConfigBagTest.php` | New: `per_jurisdiction_lines` boolish coercion + default-false test. |

**Why a separate `JurisdictionSummary`, not just thread the SDK's `JurisdictionRate` straight through.** The SDK's `JurisdictionRate` lives on `CalculatedLine`, so summing across multiple cart lines requires grouping logic. Pulling that grouping into a typed unit makes it testable without OpenCart.

**Sort-order math.** The connector's existing `sort_order` is read from `module_opensalestax_sort_order` (default 5). Per-jurisdiction lines use `base + offset` where offset is `0/1/2/3` by jurisdiction type. State first, special last — matches typical US tax-receipt ordering.

### 2. DNS-rebinding IP-pinning

| File | Change |
|---|---|
| `src/Support/UrlValidator.php` | New method `validateAndResolve(string $url): array{0: string, 1: string}` — returns `[$cleanUrl, $pinnedIp]`. Existing `validate(string $url): void` becomes a wrapper. Public host validator returns the first **public** IP it sees (or the literal IP for IP-URLs); private-net path returns the resolved IP unchanged. |
| `src/Support/OpenSalesTaxClientFactory.php` | When validator succeeds, capture `$pinnedIp`. Build Guzzle with `curl` options including `CURLOPT_RESOLVE => ["{$host}:{$port}:{$pinnedIp}"]` (port defaults: 443 for https, 80 for http; explicit URL port wins). When `cURL` isn't available (PHP built with `--disable-curl`), log a warning and fall back to un-pinned client. |
| `tests/Unit/Support/UrlValidatorTest.php` | New: `validateAndResolve` returns IP literal for IP-URL; returns first public IP for hostname URL; private-nets-allowed returns whatever resolver gave; throws on rejection same as before. |
| `tests/Unit/Support/OpenSalesTaxClientFactoryTest.php` | New: `make()` plumbs the pinned IP into Guzzle's curl options for the validated URL (we can't easily call out, but we can stub the validator + assert on a public accessor or via a factory subclass that exposes the built options). |

**Why pin at the cURL layer, not the DNS layer.** PHP's stream resolver is global; we can't override it per-request. cURL's `CURLOPT_RESOLVE` pins a `host:port` to a specific IP for the lifetime of the cURL handle — exactly the rebinding-defense semantic we need. Guzzle exposes cURL options through its `curl` request option.

**Why "first public IP."** When a host resolves to multiple addresses (round-robin DNS), we want a stable IP for the duration of the request and we don't want to pick a stale or unhealthy address. First public in the list is deterministic; if it's down the merchant gets a normal connection error rather than an SSRF surprise.

**TLS SNI / cert validation.** Pinning the IP doesn't change the URL — Guzzle still issues `GET https://ost.example.com/...` with `Host: ost.example.com` and SNI `ost.example.com`. cURL just opens the TCP connection to `203.0.113.42` instead of re-resolving. Cert validation works against the hostname.

### Backwards-compat semantics

- **ConfigBag**: new `perJurisdictionLines` defaults to false; v0.1.1 install reads no `per_jurisdiction_lines` setting key from OC's table → default applies.
- **UrlValidator**: existing `validate()` keeps its `void` return type. Admin form's save action calls it unchanged.
- **Per-jurisdiction lines**: opt-in via admin toggle. Existing merchants see no UI change unless they enable it.
- **IP-pinning**: always-on. Merchants whose DNS isn't compromised see no behavioral change.

## Threat-model deltas

| Threat | Mitigation in this phase |
|---|---|
| **DNS rebinding** (post-save IP swap) | `UrlValidator::validateAndResolve()` captures the resolved IP at config-validation time; the client factory pins the host to that IP via `CURLOPT_RESOLVE`. The runtime request reaches the validated IP, not whatever DNS resolves to during checkout. Status: was "Open" finding in v0.1, becomes **Mitigated** in v0.2. |
| **IP-pinning bypass via Host header** | Not applicable — we don't expose user-controlled headers to the SDK request. |
| **Per-jurisdiction line code collision with merchant's other extensions** | Distinct `code`s prefixed `opensalestax_*` — namespaced. If a third-party extension uses the same code, merchant disables one or the other; documented as a known compat note. |
| **Per-jurisdiction line title injection** | Engine returns `name` as a string; we pass it to OpenCart's totals array which goes through Twig auto-escape in checkout views. No raw HTML insertion. |

## CI / SonarQube

No change to workflow YAML. Re-scan SonarQube after Phase 03 ships. Target: maintain 0/0/0/0. The cURL options use array literals (no string concat); the JurisdictionSummary aggregator uses bcmath-free decimal addition via `number_format(floatval, 2)` for the cent-rounding-resistant sum.

## v0.3+ candidates (still deferred)

- OC 3.x backport (Phase 04)
- Shipping-tax integration (Phase 05)
- Multi-store (Phase 06)
- Marketplace submission (Phase 07)
