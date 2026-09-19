# Telegram automation

Telegram is an **outbound notification channel** for COS events.

Business capabilities write notifications to `tn_notification_outbox`. Symfony owns reminder scheduling, outbox delivery and operational health checks.

## Environment

Required variables:

```dotenv
TELEGRAM_BOT_TOKEN=...
TELEGRAM_BOT_NAME=...
APP_URL=https://example.com
```

Do not store bot tokens in PHP configuration or in the repository. During the final runtime cutover, `deploy/symfony-dev.sh` persists these values into the Symfony runtime environment so the channel no longer depends on the compatibility PHP container.

## Inbound bot status

The legacy Longman/Phalcon inbound bot runtime is retired.

- `app/bootstrap_tg.php` and `Interfaces/Telegram` do not exist.
- legacy command handling and Phalcon ActiveRecord mappings are removed.
- legacy Telegram webhook/worker scripts are removed.
- `public/tgAdmin_webhook.php` remains only as an HTTP 410 tombstone so stale external webhook configuration cannot boot old code.

A future interactive Telegram bot must be implemented as a new canonical transport, not by restoring the retired Phalcon runtime.

## Outbound runtime

The canonical worker is the `telegram-worker` service in `docker-compose.symfony.yml`. It runs:

```bash
php bin/console cos:telegram:process --schedule --limit=50
```

The command queues due reminders, drains `tn_notification_outbox`, resolves existing Telegram bindings and sends messages through the Telegram Bot API using the Symfony runtime.

To queue the manager digest explicitly:

```bash
docker compose -f docker-compose.symfony.yml exec php php bin/console cos:telegram:process --digest --limit=50
```

Inspect Telegram-side health without printing the token:

```bash
docker compose -f docker-compose.symfony.yml exec php php bin/console cos:telegram:health
```

Retries use an exponential delay and stop after five attempts. Events without a connected recipient are marked `skipped`; they do not block application requests.
