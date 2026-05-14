# Security Policy

## Supported versions

The project is in alpha. Only the latest tagged release receives security fixes.

## Reporting a vulnerability

**Do NOT open a public GitHub issue for security reports.**

Email **ejosterberg@gmail.com** directly with:

- A description of the issue
- Reproduction steps
- The affected version (output of `git describe --tags`)
- Your assessment of severity / exploitability

Expect acknowledgement within 5 business days. A coordinated disclosure window will be agreed before public disclosure.

Once a fix lands, the disclosure will be coordinated via:

- A CVE if the issue is widely exploitable
- A GitHub Security Advisory on the repo
- A note in `CHANGELOG.md` with the fix version

## Scope

In scope:

- The connector's own code (`src/`, `extension/upload/`, `tools/`)
- The bundled `vendor/` artifact in the produced `.ocmod.zip`
- Configuration handling, including the SSRF defense

Out of scope:

- Vulnerabilities in OpenCart core itself (report those upstream to [opencart/opencart](https://github.com/opencart/opencart/issues))
- Vulnerabilities in the OpenSalesTax engine (report those upstream to [ejosterberg/opensalestax](https://github.com/ejosterberg/opensalestax/issues))
- Issues where the attacker already has admin access to the OpenCart installation — that's a privilege-escalation against OpenCart, not this extension

## Current threat-model snapshot

See [`docs/SECURITY-REVIEW.md`](docs/SECURITY-REVIEW.md) for the full review (≥ 10 threats, mitigations, residual risk).

## Bug bounty

This project does not currently offer a bug bounty.
