---
title: Domain Process Coverage
description: Generated coverage of installable COS Domains by canonical Process Registry models.
status: generated
updated: 2026-09-15
kind: reference
contract: reference-v1
generated: true
---

# Domain Process Coverage

Generated from installable `app/Domains/*/module.php`, Process Registry definitions and explicit process-coverage exemptions. Do not edit this page manually.

This reference answers a deliberately uncomfortable question: does every installable business Domain have at least one canonical process model, or has it explicitly justified why it does not?

## Summary

- **Installable Domains:** 3
- **Covered by canonical process:** 3
- **Explicit exemptions:** 0
- **Missing coverage:** 0

| Domain | Version | Status | Processes | Steps | Capability mapped | Capability gaps | Debt |
| --- | --- | --- | ---: | ---: | ---: | ---: | ---: |
| `diagnostic` · Diagnostics | `0.6.1` | `covered` | 1 | 7 | 0/7 | 7 | 7 |
| `property` · Property | `0.12.0` | `covered` | 1 | 6 | 6/6 | 0 | 0 |
| `sales` · Sales | `0.8.6` | `covered` | 1 | 8 | 0/8 | 8 | 8 |

## Canonical process ownership

### Diagnostics (`diagnostic`)

- `diagnostic.session-to-recommendation`

### Property (`property`)

- `property.submission-to-publication`

### Sales (`sales`)

- `sales.lead-to-managed-case`

## Coverage contract

1. Only directories with a canonical `module.php` are installable Domains for this gate.
2. Every installable Domain must own at least one Process Registry definition or have one explicit exemption.
3. Every Process Registry `domain` must resolve to an installable module manifest.
4. An exemption is invalid once the Domain gains a canonical process.
5. Supporting Domain directories without `module.php` are not silently promoted to installable Domains by documentation.

## Exemptions

No active exemptions.

## Authority and limitations

- Module manifests define which Domains are installable.
- Process Registry defines canonical process ownership.
- `process-coverage-exemptions.json` records only explicit architecture exceptions.
- Domain directories without `module.php` remain supporting/non-installable areas and are not coverage failures.
- Coverage means a Domain has a canonical process model; it does not claim the model is complete, automated or runtime-verified.
