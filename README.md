# OpenSalesTax for OpenCart

> **v0.2.0** — installable; 106 unit tests + SonarQube green; per-jurisdiction tax-line surface (opt-in) and cURL IP-pinning against DNS rebinding. Cart-signature cache key, customer-group exemptions, and an admin "Test Connection" button (all shipped in v0.1.1) remain. Not yet end-to-end validated on a live OpenCart storefront. See [`specs/`](specs/) for the build plan and roadmap.

> **Tax calculations are provided as-is for convenience.** The merchant is solely responsible for tax-collection accuracy and remittance to the appropriate jurisdictions. **Verify against your state Department of Revenue before remitting.**

A free, self-hostable OpenCart 4.x extension that swaps OpenCart's geo-zone-based tax tables for the [OpenSalesTax engine](https://github.com/ejosterberg/opensalestax) on US-destination, USD checkouts. No per-transaction fees, no SaaS lock-in — merchants run both OpenCart and OpenSalesTax on their own infrastructure.

## What this extension does

- Registers an OpenCart 4.x order-total extension (`extension/total/opensalestax`) that runs during cart-total computation.
- On a configured, US-shipping, USD-priced cart: calls your OpenSalesTax engine's `POST /v1/calculate` and adds the returned tax as a top-level total line.
- Caches per-ZIP rate lookups in OpenCart's cache layer (default 24h TTL).
- Falls back to OpenCart's built-in tax tables on non-US destinations, non-USD currencies, missing ZIPs, or engine errors (default fail-soft behavior).

## What this extension does NOT do

- File or remit tax (calculation only — the merchant remits)
- Validate addresses
- Handle non-USD currencies or non-US destinations (passes those through to OpenCart)
- Reverse-engineer Avalara / TaxJar / Vertex integration code (we read primary sources only)
- Ship with the engine bundled — point it at your own [OpenSalesTax engine](https://github.com/ejosterberg/opensalestax)

## Compatibility matrix

| OpenCart | PHP | Status |
|---|---|---|
| 4.0.x | 8.0 / 8.1 / 8.2 / 8.3 | Targeted by v0.1 (live integration test pending) |
| 4.1.x | 8.1 / 8.2 / 8.3 | Targeted by v0.1 (live integration test pending) |
| 3.0.x | 7.4 / 8.0 | **Not supported.** v0.2 backport candidate. |
| 2.x | — | Not supported. |

## Requirements

- OpenCart 4.0+ (PHP 8.0+ at the OpenCart baseline; we recommend 8.2+).
- A reachable [OpenSalesTax engine](https://github.com/ejosterberg/opensalestax) instance (v0.55+ verified).

## Install

### From the GitHub Releases `.ocmod.zip` (recommended)

1. Download the latest `opensalestax-opencart-vX.Y.Z.ocmod.zip` from [Releases](https://github.com/ejosterberg/opensalestax-opencart/releases).
2. In your OpenCart admin: **Extensions → Installer → Upload**. Select the file.
3. **Extensions → Extensions**, choose **Order Totals** in the dropdown, find **OpenSalesTax**, click the green **+** install button.
4. Click **Edit** to open the settings page (see "Configure" below).

### From source

```bash
git clone https://github.com/ejosterberg/opensalestax-opencart.git
cd opensalestax-opencart
composer install --no-dev
tools/build-ocmod.sh
# Upload dist/opensalestax-opencart-vX.Y.Z.ocmod.zip via OpenCart admin
```

## Configure

**Admin → Extensions → Extensions → Order Totals → OpenSalesTax → Edit**

| Field | Default | Purpose |
|---|---|---|
| Enabled | No | Master switch. |
| Engine base URL | (empty) | Base URL of your OST engine, e.g. `https://ost.example.com`. **Required** when enabled. |
| API key (optional) | (empty) | Bearer token if your engine requires authentication. |
| HTTP timeout (seconds) | 10 | Per-request timeout. |
| Verify TLS certificate | Yes | Strongly recommended ON. Disable only for self-signed engine certs. |
| Allow private network engines | No | Permit RFC1918 / loopback / link-local hosts. Required if your engine is on the same LAN as OpenCart. |
| Block checkout on engine error | No | When ON, an unreachable engine throws an error. When OFF (default), OpenCart's built-in tax handles the cart. |
| Cache TTL (seconds) | 86400 | Per-ZIP cache lifetime. The cache key also includes a stable signature of the cart's `(category, amount)` tuples, so mixed-category carts at the same ZIP don't share cached results. |
| Exempt customer groups | (empty) | Comma-separated OpenCart customer-group IDs (e.g. `2,3`) that bypass real-time tax calculation. Typical use: B2B / wholesale / nonprofit groups already mapped to OpenCart's tax classes. Leave blank for no exemptions. |
| Show tax breakdown per jurisdiction | No | When ON, the cart shows a separate total line per jurisdiction (state / county / city / special) labeled like "Minnesota State Tax", "Hennepin County Tax". When OFF (default), a single aggregate "Sales Tax" line. |

Click **Test Connection** at the bottom of the settings form to verify your engine URL / API key / TLS settings without putting a cart together. The button calls your engine's `/v1/health` and reports `ok / engine version` (or the failure reason). The button is ACL-gated to the same `modify` permission as the save action.

While the extension is disabled or `base_url` is empty, OpenCart's built-in tax flow runs unmodified.

## How it works

1. At checkout, OpenCart's `Cart\Total::getTotals()` walks the enabled order-total extensions. Our `catalog/model/extension/opensalestax/total/opensalestax::getTotal()` is invoked.
2. We inspect the cart products + the session's shipping address. If the cart is empty, the currency isn't USD, the shipping country isn't US, or the ZIP isn't 5 digits, we return without touching the totals.
3. The base URL is validated (SSRF-defending — RFC1918 / loopback / link-local / CGNAT / multicast all rejected by default; opt-in toggle for private-LAN engines).
4. We cache-lookup the engine response keyed by ZIP-5. On miss, we call `POST /v1/calculate` via the [`ejosterberg/opensalestax`](https://packagist.org/packages/ejosterberg/opensalestax) PHP SDK and store the response.
5. The engine's `tax_total` is added as a top-level total line (`code: opensalestax`) — visible in the cart, checkout, and order summary screens.

If any step fails (gate, network, malformed response), the default fail-soft policy logs a structured warning and lets OpenCart's built-in tax flow continue. Fail-hard mode rethrows the error, blocking the cart.

## Logging

All engine interactions log structured metadata (ZIP-5, RTT in ms, line count) via OpenCart's `\Log`. **Customer addresses and full payloads are never logged.** The API key (if configured) flows in memory only — never to logs.

## Development

```bash
composer install
composer test           # PHPUnit
composer stan           # PHPStan max
composer cs             # PHP-CS-Fixer dry-run
composer audit          # Known-CVE check
composer check          # all of the above
```

See [`CONTRIBUTING.md`](CONTRIBUTING.md) for branch model, DCO sign-off, and the quality gate.

## Security

Found a vulnerability? See [`SECURITY.md`](SECURITY.md). Don't open public GitHub issues for security bugs.

The threat model and current findings are in [`docs/SECURITY-REVIEW.md`](docs/SECURITY-REVIEW.md).

## Roadmap

- **v0.1.x** — Live integration test on a real OpenCart 4.x storefront; OpenCart Marketplace submission.
- **v0.3** — OpenCart 3.x backport (OCMOD path); shipping-tax integration; multi-store support.

Shipped:
- **v0.1.1** — cart-signature cache key, customer-group exemptions, admin "Test Connection" button.
- **v0.2.0** — per-jurisdiction tax-line surface (opt-in), cURL IP-pinning against DNS rebinding.

## Related projects

- [OpenSalesTax engine](https://github.com/ejosterberg/opensalestax) — the calculation API this extension consumes.
- [`ejosterberg/opensalestax` PHP SDK](https://packagist.org/packages/ejosterberg/opensalestax) — the wire-level client this extension wraps.
- [opensalestax-magento](https://github.com/ejosterberg/opensalestax-magento) — same engine, Magento 2 connector.
- [opensalestax-bagisto](https://github.com/ejosterberg/opensalestax-bagisto) — same engine, Bagisto / Laravel connector.

## License

Dual-licensed under your choice of [Apache-2.0](LICENSE-APACHE.txt) OR [GPL-2.0-or-later](LICENSE-GPL.txt). See [`LICENSE`](LICENSE). DCO sign-off (`git commit -s`) required on every commit.

OpenCart core is GPL-3.0. Both Apache-2.0 and GPL-2.0-or-later are compatible with GPL-3.0 (per the FSF compatibility chart); this extension is delivered as a separate package that runs inside OpenCart's plugin contract.
