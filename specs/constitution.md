# Constitution — opensalestax-opencart

> Non-negotiable principles for this connector. Inherits from the umbrella program constitution in `ejosterberg/open-sales-tax-integrations` (private).

## §1. Purpose

Ship an OpenCart extension that calculates US sales tax via a merchant-self-hosted OpenSalesTax engine. Replace OpenCart's geo-zone-based tax tables with destination-based real-time calculation for US-shipping, USD-priced carts. Pass through all other shapes.

## §2. License — Apache 2.0

This connector ships under Apache 2.0. OpenCart core is GPLv3; per the FSF compatibility chart, Apache 2.0 may be combined with GPLv3 in distributions where the combined work is GPLv3. We do not redistribute OpenCart core — we ship an extension that runs inside it, so the Apache 2.0 license applies cleanly to our source. DCO sign-off + SPDX headers mandatory on every file.

## §3. SDK-only path to the engine

The connector consumes the engine via `ejosterberg/opensalestax` (PHP SDK, on Packagist). We never call engine HTTP endpoints directly. If the SDK lacks something we need, file it upstream.

## §4. Calculation only

No filing. No remittance. No address validation. The merchant remits. Every user-facing tax line carries the calculation-only disclaimer.

## §5. US-only, USD-only

The engine supports US destinations + USD line amounts only. Out of those boundaries we yield to OpenCart's built-in tax. We do not pretend to handle multi-currency or non-US tax — those are the merchant's problem.

## §6. Fail-soft default

If the engine is unreachable, malformed, or misconfigured, the extension falls back to OpenCart's built-in tax and logs a structured warning. The merchant opts into fail-hard via the admin settings; the default is fail-soft because a checkout-blocking error in production loses the merchant more money than a brief tax-table fallback.

## §7. SSRF defense

The engine base URL is admin-controlled, which puts SSRF in the threat model. The URL validator rejects RFC1918 / loopback / link-local / CGNAT / multicast hosts by default. Merchants who legitimately self-host on a private LAN opt in via the "Allow private network engines" toggle.

## §8. Never log secrets or PII

The OST API key (if configured) flows in-memory only. Structured logs carry numeric metadata (status, RTT, line count) — never customer addresses, cart contents, or credentials.

## §9. DCO required

Every commit `-s` signed. CI enforces. No AI co-author trailers.

## §10. Disclaimer everywhere user sees tax

> Tax calculations are provided as-is for convenience. The merchant is solely responsible for tax-collection accuracy and remittance to the appropriate jurisdictions. Verify against your state Department of Revenue before remitting.

This wording appears: README top, admin settings page, and (when possible) the customer-facing checkout tax line tooltip in v0.2.
