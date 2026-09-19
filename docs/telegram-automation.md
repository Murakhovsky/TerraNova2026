# Telegram automation

Telegram is retained as an **outbound notification channel** for COS events.

Business capabilities write notifications to `tn_notification_outbox`. The framework-neutral automation service and worker deliver those messages asynchronously through Telegram.

## Environment

Required variables:

```dotenv
TELEGRAM_BOT_TOKEN=...
TELEGRAM_BOT_NAME=...
APP_URL=https://example.com
```

Do not store bot tokens in PHP configuration or in the repository.

## Inbound bot status

The legacy Longman/Phalcon inbound bot runtime is retired.

- `app/bootstrap_tg.php` and `Interfaces/Telegram` no longer exist.
- legacy command handling and Phalcon ActiveRecord mappings are removed.
- `bin/telegram-webhook.php` is removed.
- `public/tgAdmin_webhook.php` remains only as an HTTP 410 tombstone so stale external webhook configuration cannot boot old code.

A future interactive Telegram bot must be implemented as a new canonical transport, not by restoring the retired Phalcon runtime.

`bin/telegram-health.php` may still be used to inspect Telegram-side configuration while stale webhook settings are being removed.

## Outbound worker

The current outbound notification worker processes `tn_notification_outbox`, resolves existing Telegram bindings and sends queued messages.

Until the worker is moved to Symfony in the next retirement slice, its business-independent components remain:

- `Infrastructure\Integration\Telegram\TelegramAutomationService`
- `Infrastructure\Integration\Telegram\TelegramAutomationProcessor`

Retries use an exponential delay and stop after five attempts. Events without a connected recipient are marked `skipped`; they do not block application requests.
