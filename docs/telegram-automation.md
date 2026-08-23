# Telegram automation

Telegram is a delivery channel for Terra Nova events. Business modules write notifications to
`tn_notification_outbox`; the worker sends them asynchronously through Longman.

## Environment

Required variables:

```dotenv
TELEGRAM_BOT_TOKEN=...
TELEGRAM_BOT_NAME=...
APP_URL=https://example.com
TELEGRAM_WEBHOOK_URL=https://example.com/tgAdmin_webhook.php
TELEGRAM_WEBHOOK_SECRET=long-random-value
```

Do not store bot tokens in PHP configuration or in the repository.

## Webhook

Configure Telegram to send updates to the public `POST /TgAdmin/webhook` endpoint and pass the
same `TELEGRAM_WEBHOOK_SECRET` as Telegram's `secret_token`. The webhook
uses the existing Longman command loader. A user connects an account from `/cabinet`; `/start`
consumes the one-time token and creates a binding to `tn_users`.

Register or refresh the webhook after deployment:

```bash
php bin/telegram-webhook.php
```

Available commands:

- `/start` connects the bot or shows the current binding.
- `/tasks` shows a manager/admin operational snapshot.

## Worker

Run every minute:

```bash
php bin/telegram-worker.php --schedule --limit=50
```

Run once each morning for the team digest:

```bash
php bin/telegram-worker.php --digest --limit=50
```

Retries use an exponential delay and stop after five attempts. Events without a connected
recipient are marked `skipped`; they do not block application requests.
