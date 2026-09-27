---
title: Growth credentialed JSON collector
description: Provider-neutral authenticated HTTPS pull contract for external Growth signals.
status: active
updated: 2026-09-24
kind: integration
---

# Growth credentialed JSON collector

Collector name:

```text
credentialed_json
```

## Source configuration

Create a tenant source through:

```text
POST /api/v1/growth/json-signal-sources
```

Example:

```json
{
  "name": "Provider events",
  "url": "https://api.provider.example/v1/growth-signals",
  "auth_mode": "bearer",
  "credential_reference": "env://GROWTH_PROVIDER_ACME",
  "api_key_header": null,
  "subject_type": "account",
  "subject_id": "account-42",
  "signal_type": "provider_event",
  "confidence": 0.85,
  "enabled": true
}
```

Supported auth modes:

- `bearer`: vault material must contain `token`;
- `api_key_header`: vault material must contain `api_key` and source configuration must provide a safe `X-*` header name.

The API never returns the credential reference. Read models expose only whether credentials are configured plus a short hash of the reference.

## Credential vault

The first concrete Platform vault adapter supports:

```text
env://VARIABLE_NAME
```

The environment variable must contain a JSON object:

```json
{"token":"provider-bearer-token"}
```

or:

```json
{"api_key":"provider-api-key"}
```

Raw secret material is not stored in Growth tables, Domain Events or Audit data.

## Provider response contract

The endpoint is called with an appended `limit` query parameter and must return:

```json
{
  "items": [
    {
      "id": "event-123",
      "occurred_at": "2026-09-24T12:00:00+00:00",
      "source_reference": "https://provider.example/events/123",
      "facts": {
        "kind": "funding",
        "stage": "series_a",
        "verified": true
      }
    }
  ]
}
```

Facts are limited to scalar/null values, at most 50 fields per item. Invalid records are skipped; valid records are sorted newest-first before collector-level limit is applied.

## Network safety

The transport:

- requires HTTPS on port 443;
- rejects embedded credentials, fragments, localhost/private/reserved endpoints;
- resolves only public IPv4 and pins it with cURL `CURLOPT_RESOLVE`;
- follows no redirects;
- caps response body at 2 MB;
- accepts JSON responses only;
- uses the canonical `ExternalCallExecutor` retry/circuit-breaker policy.

## Dedupe

The collector does not use cursors. Stable provider `id` plus source/subject/signal mapping produces a deterministic external key. Existing Growth source receipts provide durable dedupe across repeated polling runs.
