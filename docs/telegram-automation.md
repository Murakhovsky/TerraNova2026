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

The canonical outbound worker is Symfony Console:

```bash
docker compose -f docker-compose.symfony.yml exec -T php \
  php bin/console cos:telegram:notifications:process --schedule --limit=50
```

Run the digest explicitly on the desired morning cadence:

```bash
docker compose -f docker-compose.symfony.yml exec -T php \
  php bin/console cos:telegram:notifications:process --digest --limit=50
```

The command uses the existing `tn_notification_outbox`, `TelegramAutomationService` and `TelegramAutomationProcessor`, but delivery is now a framework-neutral Telegram Bot API cURL adapter. It does not boot Phalcon and does not require the retired Longman command runtime.

`--schedule` preserves the old due-reminder enqueue behavior, `--digest` preserves daily management digest enqueueing, and `--limit` controls the claimed outbox batch.

Retries use an exponential delay and stop after five attempts. Events without a connected recipient are marked `skipped`; they do not block application requests.
