# COS Platform Notification

`Platform\\Notification` is the canonical outbound notification boundary.

Domains do not call SMTP, Telegram, SMS, Push or WebSocket SDKs. They dispatch a `SendNotificationCommand` (normally through the command bus).

```text
Domain/Application
    ↓ SendNotificationCommand
Platform Notification
    ↓ NotificationDispatcher
Template → Renderer
    ↓
NotificationChannelInterface
    ↓
Infrastructure adapter
    ↓
Email / Telegram / Push / SMS / WebSocket provider
```

## Canonical model

- `Notification` — tenant-scoped intent to notify.
- `Channel` — email, telegram, push, sms, websocket.
- `Template` — localized channel-specific template.
- `Recipient` — destination plus optional user identity/metadata.
- `Delivery` — durable delivery outcome and provider message id.

`NotificationDispatcher` resolves the template, renders it, selects the registered channel adapter and persists the resulting Delivery.


## Production runtime: durable email channel

As of the Growth V0.24 integration, Platform Notification has its first production-backed channel: `email`.

```text
Domain-owned outbound port
    ↓
NotificationDispatcher
    ↓
builtin template + renderer
    ↓
N8nEmailNotificationChannel
    ↓
tn_integration_outbox
    ↓
cos:integration:n8n:process
    ↓
signed n8n webhook
    ↓
email provider workflow
```

The channel does not call SMTP or an email SDK directly. It writes a semantically idempotent `notification.email` envelope to the existing durable n8n integration outbox. The existing integration processor owns retries and external webhook delivery.

`cos_notification_deliveries` is the Platform-owned delivery ledger. It records notification/delivery identity, tenant, channel, recipient, status, attempts, provider reference and metadata. A duplicate delivery id with another notification/channel/recipient is rejected as an idempotency conflict.

For this runtime, `QUEUED` means the message has been durably accepted into the integration outbox. It does **not** mean the external email provider has delivered the message. `SENT` reflects an already-sent outbox row when the same notification is resolved again. Failed outbox state remains retryable by the integration processor and is surfaced to Kernel execution as an external-unavailable failure.

The first built-in template is `growth.outbound.message` for email. Its body/subject come from already-governed Growth execution input; Platform Notification does not generate marketing copy.

The n8n receiver for `notification.email` is expected to deliver the envelope to the configured email provider and preserve the incoming idempotency key.
