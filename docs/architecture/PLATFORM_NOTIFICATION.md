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
