# Phase 02 — Plan

## File-level changes

### 1. Cart-signature cache key

| File | Change |
|---|---|
| `src/Support/CartPayloadBuilder.php` | Return shape becomes `[Address, LineItem[], string $signature]` (3-tuple). Signature is `substr(sha256(sorted "cat:amount" tuples), 0, 16)` — 16 hex chars is enough collision-resistance for a per-ZIP cache. Empty `$lineItems` already short-circuits; signature is `""` only in tests that build it artificially. |
| `src/Support/RateCache.php` | `keyFor()` accepts a second optional `?string $cartSignature = null` arg. When provided and non-empty, key is `ost:rate:{zip5}:{sig}`; when null/empty, key is the v0.1 shape `ost:rate:{zip5}`. `remember()` accepts the signature and threads it through. |
| `src/Support/TaxCalculator.php` | `prepare()` returns the 3-tuple; `calculate()` passes the signature into `$this->cache->remember()`. |
| `tests/Unit/Support/CartPayloadBuilderTest.php` | New cases: signature is deterministic + reorder-stable + category-aware + amount-aware. |
| `tests/Unit/Support/RateCacheTest.php` | New cases: `keyFor()` shape with + without signature; signatures bypass each other's cached values; missing signature falls through to legacy key. |
| `tests/Unit/Support/TaxCalculatorTest.php` | New case: two carts at the same ZIP with different signatures both call the engine (no cross-cart cache hit). |

**Signature design rationale.** SHA-256 over the canonical `category:amount` tuples sorted lexicographically. 16-hex-char prefix (8 bytes) — collision probability for the realistic case of < 10k distinct cart shapes per ZIP per TTL window is negligible (~2^-32 per pair). We use full-string hashing (not a homemade XOR) to keep collisions provably bounded; we truncate because OpenCart's cache backends (file, apcu, memcached) all prefer shorter keys.

### 2. Customer-group exemptions

| File | Change |
|---|---|
| `src/Support/ConfigBag.php` | New readonly field `array $exemptCustomerGroupIds`. `fromArray()` parses comma-separated `exempt_customer_group_ids` string into a deduped sorted `int[]`. |
| `src/Support/TaxCalculator.php` | `calculate()` accepts a new optional `?int $customerGroupId = null` param. When the bag's exempt list contains it, return null **before** the payload build (no engine call, no log spam). |
| `extension/upload/catalog/model/extension/opensalestax/total/opensalestax.php` | Read `$this->customer->getGroupId()` (with `method_exists` guard for OC contract drift) and pass it to `$calculator->calculate()`. Same fail-soft semantics if it throws. |
| `extension/upload/admin/controller/extension/opensalestax/total/opensalestax.php` | Whitelist `module_opensalestax_exempt_customer_group_ids` in the `index()` defaults. No special validation — the string passes through; bad chars degrade harmlessly to "no exemptions". |
| `extension/upload/admin/view/template/extension/opensalestax/total/opensalestax.twig` | New text input row, after the cache-TTL row. |
| `extension/upload/admin/language/en-gb/extension/opensalestax/total/opensalestax.php` | New `entry_exempt_groups` + `help_exempt_groups` strings. |
| `tests/Unit/Support/ConfigBagTest.php` | New: `"2,3"` → `[2,3]`; `"  2 , 3 ,3 "` → `[2,3]` (trim + dedupe); `""` → `[]`; `"abc"` → `[]`; `"0"` → `[0]` (yes, guests are an explicit valid group). |
| `tests/Unit/Support/TaxCalculatorTest.php` | New: exempt group short-circuits before payload build; non-exempt group flows normally; null group ID falls through normally. |

**Why a plain int list, not a typed object.** OpenCart's `customer_group_id` is an `int`. Wrapping it in a value object adds nothing testable for this surface. Tests assert against `int[]` directly.

### 3. Admin "Test Connection" button

| File | Change |
|---|---|
| `extension/upload/admin/controller/extension/opensalestax/total/opensalestax.php` | New `testConnection()` controller action. Re-uses `UrlValidator` + builds an SDK `Client` directly (not the full `TaxCalculator` — we don't want to require a cart). Calls `$client->health()`; on success returns `{ok: true, message: "Engine reachable", version: $health->version}`; on failure returns `{ok: false, message: $e->getMessage()}`. Same `user->hasPermission('modify', ...)` ACL gate as `save()`. |
| `extension/upload/admin/view/template/extension/opensalestax/total/opensalestax.twig` | New button + result panel (Bootstrap alert) below the form. Hits the new route via fetch; renders the JSON result. |
| `extension/upload/admin/language/en-gb/extension/opensalestax/total/opensalestax.php` | New `button_test_connection`, `text_testing`, `text_test_ok`, `text_test_fail` strings. |

**Why not a unit test for the controller.** The controller is glue — it pulls values out of `$_POST`, runs them through tested units (`UrlValidator`, SDK `Client`), and renders. The tested unit is the validator; the SDK has its own tests upstream. We DO add a smoke check in `tools/smoke-test.php` so a real-engine integration confirms the wire path.

### Backwards-compat semantics

- **Cache keys**: a v0.1 install has cache entries shaped `ost:rate:55401`. Post-upgrade, `keyFor('55401', '<sig>')` writes `ost:rate:55401:<sig>` and reads return null on first hit. The old `ost:rate:55401` entries simply expire on their original TTL (default 24h). No migration code; no breakage.
- **ConfigBag**: the new field defaults to `[]` (no exemptions). A v0.1 install reads no `exempt_customer_group_ids` key from OC's `setting` table — defaults apply transparently.
- **Admin form**: the new input + button are additive. The save path persists the new key alongside the existing ones via OC's `editSetting()`.

## Threat-model deltas

| Threat | Mitigation in this phase |
|---|---|
| **Customer-group ID tampering** — could a customer escalate to an exempt group via cookie/session forgery? | Out of scope. OpenCart's `Cart\Customer::getGroupId()` reads from the authenticated customer record, not from request headers. If OC's customer auth is compromised, exempting them from tax is the least of the merchant's problems. |
| **Cache poisoning via cart-signature collision** | Acceptable risk. 64-bit truncated SHA-256 over `(category, amount)` tuples is collision-bound far below realistic per-merchant cart cardinality. Even a successful collision only swaps two carts' cached engine responses — both responses are merchant-internal data, no privilege boundary crossed. |
| **Test Connection abused as SSRF probe** | Same controls as the save path: ACL-gated (`modify` permission), URL run through `UrlValidator`, only HTTP/HTTPS schemes accepted. Restricted to admin users who can already configure the engine URL. |
| **Test Connection leaks engine version to UI** | Engine version is non-secret (visible in engine's own `/v1/health` endpoint). Merchant sees it; no PII or credentials. |

## CI

No change to the workflow YAML. The existing matrix (PHP 8.1/8.2/8.3 + PHPStan + PHP-CS-Fixer + composer audit) covers the new units.

## SonarQube

Re-scan after Phase 02 ships. Target: maintain 0/0/0/0. The signature hashing uses `hash('sha256', $str)` (not `md5`); the comma-separated-list parser uses `array_filter` + `array_map` (no regex needed).

## v0.2 candidates (still deferred)

Same list as in `specs/phase-01-v0.1.0-alpha/plan.md`, less the three items shipped here. Re-checked at end of Phase 02 to feed into Phase 03 spec.
