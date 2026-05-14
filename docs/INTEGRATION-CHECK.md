# Integration Check — opensalestax-opencart

This document records the manual smoke test that verifies the connector successfully round-trips a calculation against a real OpenSalesTax engine. The full live integration test (cart-on-real-OpenCart-storefront) is performed by the parent orchestrator agent against a pre-provisioned VM and is not in scope here.

## Goal

Prove that the connector's `TaxCalculator` pipeline:

1. Builds a valid SDK `Address` + `LineItem[]` from a representative OpenCart cart shape
2. Calls the engine's `POST /v1/calculate` endpoint
3. Receives a structured `CalculateResponse`
4. Returns a non-zero `tax_total` for a US-shipping, USD-priced cart

This is the smallest end-to-end check that exercises every code path the live integration depends on, without requiring an OpenCart runtime.

## Prerequisites

- PHP 8.1+ (CLI)
- `composer install` has been run in the repo root
- A reachable OpenSalesTax engine. The default smoke test URL is `http://10.32.161.126:8080` (Eric's lab engine).

## Procedure

From the repo root:

```bash
OST_ENGINE_URL=http://10.32.161.126:8080 \
OST_ALLOW_PRIVATE=1 \
    /c/xampp/8.2.4/php/php.exe tools/smoke-test.php
```

(On non-Windows: replace the PHP path with `php`.)

Expected output:

```
OpenSalesTax connector smoke test
  engine: http://10.32.161.126:8080
  ZIP:    55401  amount: $100.00

INFO  opensalestax: engine /v1/calculate ok {"zip5":"55401","rtt_ms":<N>,"line_count":1}
tax_total: 9.0250
subtotal:  100.00
lines:     1
disclaimer: Calculation only; not legal or tax advice. Verify against your state Department of Revenue before remitting.
```

Exit code `0` means the engine returned a non-zero tax. Exit code `1` means the connector yielded (gate fail or fail-soft) — investigate the warning log line. Exit code `2` means an exception bubbled past the connector — usually an engine network error.

## Last successful run

| Date | Engine version | ZIP | Amount | Tax | Exit |
|---|---|---|---|---|---|
| 2026-05-13 | 0.55.4 | 55401 | $100.00 | $9.0250 | 0 |

## What this smoke test does NOT cover

- Cart-totals integration with a live OpenCart 4.x storefront (deferred to the orchestrator agent's run against VM 919).
- The `.ocmod.zip` install flow via the OpenCart admin Installer UI.
- The settings page form (server-side validation is unit-tested; the UI rendering is unit-tested only at the controller level).
- Concurrent request behavior under cache contention.

If any of those fail in the live integration, file the finding in `specs/phase-02-live-integration/` (a phase yet to be created) and update `CHANGELOG.md`.

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `Connector yielded` + exit 1 | Engine URL rejected by SSRF defense | Set `OST_ALLOW_PRIVATE=1` for private-LAN engines |
| `ERROR Network error` + exit 2 | Engine unreachable | `curl $OST_ENGINE_URL/v1/health` from the same host |
| `ERROR ...non-JSON 2xx body...` | Engine returned HTML / plain text | Verify the URL points at the API root (no trailing `/v1/`) |
| `tax_total: 0.00` + exit 1 | Engine has no state module loaded for that ZIP | Try ZIP 55401 (Minnesota) which is loaded in the test engine |
