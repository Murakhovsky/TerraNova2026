---
title: Growth external signal webhook
description: Signed push contract for external Growth Signal intake.
status: active
updated: 2026-09-22
kind: integration
---

# Growth external signal webhook

Endpoint:

```text
POST /webhooks/growth/signals
```

Required environment:

```dotenv
GROWTH_SIGNAL_WEBHOOK_SECRET=replace-with-random-secret
GROWTH_SIGNAL_WEBHOOK_ACTOR_ID=123
GROWTH_SIGNAL_WEBHOOK_MAX_CLOCK_SKEW=300
```

Headers:

```text
Content-Type: application/json
X-TN-Timestamp: <unix timestamp>
X-TN-Signature: sha256=<HMAC_SHA256(timestamp + "." + raw_body, secret)>
X-TN-Idempotency-Key: <stable external event id>
```

Payload:

```json
{
  "organization_id": "org-1",
  "source": "n8n",
  "signal": {
    "subject_type": "account",
    "subject_id": "account-42",
    "signal_type": "leadership_change",
    "source_reference": "https://example.com/events/42",
    "facts": {
      "role": "COO",
      "change": "joined"
    },
    "confidence": 0.91,
    "occurred_at": "2026-09-22T12:00:00+00:00"
  }
}
```

The raw body is limited to 1 MB. Timestamp must be within the configured clock-skew window. The Growth module must be enabled for `organization_id`.

The integration edge does not write Growth tables. It delegates to `GrowthApplicationBoundary::ingestExternalSignal()`, which owns validation, idempotency, Signal persistence, event publication and audit. External ingress uses SYSTEM provenance and a separate idempotency namespace from operator-created Signals.

Reusing the same idempotency key with the same payload returns the existing Signal as a replay. Reusing it with a different payload is rejected as a conflict.
