# n8n content webhooks

Terra Nova accepts idempotent, signed content updates from n8n and sends manual content changes
back through an asynchronous outbox.

## Environment

```dotenv
N8N_WEBHOOK_SECRET=shared-inbound-secret
N8N_OUTBOUND_URL=https://n8n.example.com/webhook/terra-nova-content
N8N_OUTBOUND_SECRET=shared-outbound-secret
N8N_MAX_CLOCK_SKEW=300
```

Inbound and outbound secrets should be different. HTTPS verification must remain enabled.

## Inbound endpoint

`POST /webhooks/n8n/content`

The raw request body is limited to 2 MB and must be a JSON object.

Headers:

```text
Content-Type: application/json
X-TN-Timestamp: 1787472000
X-TN-Signature: sha256=<HMAC_SHA256(timestamp + "." + raw_body, N8N_WEBHOOK_SECRET)>
X-TN-Idempotency-Key: workflow-execution-or-content-version-id
```

Payload:

```json
{
  "event": "content.upsert",
  "data": {
    "external_id": "n8n-article-1042",
    "content_type": "blog_post",
    "status": "review",
    "title": "Як підготувати квартиру до продажу",
    "slug": "yak-pidhotuvaty-kvartyru-do-prodazhu",
    "excerpt": "Короткий опис матеріалу.",
    "body_html": "<p>Перевірений HTML-контент.</p>",
    "featured_image_url": "https://example.com/image.jpg",
    "featured_image_alt": "Інтер'єр квартири",
    "meta_title": "Підготовка квартири до продажу",
    "meta_description": "Практичний чекліст Terra Nova.",
    "focus_keyword": "підготовка квартири до продажу",
    "tags": ["продаж", "квартира"]
  }
}
```

Supported events are `content.upsert`, `content.publish`, and `content.archive`. For status-only
events, `external_id` is sufficient when the material already exists. Reusing an idempotency key
with a different body returns HTTP 409.

## Outbound worker

Manual editor changes create `content.changed` events in `tn_integration_outbox`. Run every minute:

```bash
php bin/integration-worker.php --schedule-content --limit=50
```

The worker retries delivery up to five times. n8n should use the `id` field from the envelope as
its idempotency key and verify the same timestamp/HMAC headers.

Before enabling the worker in production:

1. Apply `app/migrations/20260823_000016_content_n8n.sql`.
2. Configure different inbound and outbound secrets on both sides.
3. Set `N8N_OUTBOUND_URL` to an active n8n production webhook.
4. Send a signed `content.upsert` event with `status: review`.
5. Run the worker manually and confirm both directions in `/admin/content`.
6. Add the worker command to cron or the server scheduler once per minute.
