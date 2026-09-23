---
title: Growth RSS/Atom signal collector
description: Tenant RSS/Atom pull-source contract and security constraints.
status: active
updated: 2026-09-23
kind: integration
---

# Growth RSS/Atom signal collector

V0.26 exposes a tenant-configured pull source through the canonical Growth collector runtime.

## Feed configuration

```json
{
  "name": "Company newsroom",
  "url": "https://example.com/feed.xml",
  "subject_type": "account",
  "subject_id": "account-42",
  "signal_type": "company_news",
  "confidence": 0.8,
  "enabled": true
}
```

API:

```text
GET  /api/v1/growth/signal-feeds
POST /api/v1/growth/signal-feeds
POST /api/v1/growth/signal-feeds/{id}/enable
POST /api/v1/growth/signal-feeds/{id}/disable
POST /api/v1/growth/collectors/rss_atom/run
```

Mutations use the normal Growth API security contract: tenant manage permission, CSRF, correlation and `X-Idempotency-Key`.

## Collection contract

The collector reads only enabled feeds for the tenant. Each external entry becomes a `CollectedSignal` with the configured subject mapping and confidence. The entry link is stored as `source_reference`; normalized title, summary, author and feed provenance are facts.

The collector is cursorless. Repeated polling is expected and canonical `tn_growth_signal_source_receipts` dedupe prevents duplicate Signals.

## Network security

The feed endpoint must:

- use HTTPS;
- use port 443;
- resolve to a public IPv4 address;
- not use embedded credentials or URL fragments.

The transport pins the verified DNS result with cURL `CURLOPT_RESOLVE`, disables redirects, keeps TLS verification enabled and caps the response at 2 MB. Private/reserved IPv4 ranges are rejected before the request.

RSS/Atom parsing uses `LIBXML_NONET` and does not enable external entity expansion.

## Ownership

Feed configuration and normalized Signals belong to Growth. HTTP transport and XML parsing are Infrastructure adapters. Provider entries never write Growth persistence directly.
