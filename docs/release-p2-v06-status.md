# P2 and v0.6 status

Audit date: 2026-08-23.

## Delivered in this stage

- Content domain: blog posts and managed SEO landings, publication workflow, scheduling, revisions and SEO readiness score.
- Public routes: `/blog`, `/blog/{slug}` and `/guide/{slug}` with canonical, Open Graph, robots and schema data.
- SEO discovery: published content is included in `sitemap.xml`; the public header and footer link to the blog.
- Manager UI: `/admin/content` and `/admin/content/edit/{id}` for filtering, editing, publishing and inspecting integration history.
- n8n inbound: signed HMAC webhook, five-minute clock window, mandatory idempotency key, payload size limit and upsert/publish/archive events.
- n8n outbound: transactional outbox for manual content changes, atomic claiming, five retries and delivery history.
- Content safety: HTML allowlist, safe link/image protocols, strict JSON schema object and escaped manager output.
- Operational commands: `bin/integration-worker.php` and `bin/telegram-health.php`.
- Spatial domain: versioned 3D scenes, captures, assets, property relations, hotspots, processing queue, JWT API, manager workspace and public Three.js/Spark viewer.

## P2 matrix

| P2 item | Status | Remaining work |
| --- | --- | --- |
| Presentation pages | Ready in code | Production content and final visual QA. |
| Blog | Ready in code | Editorial plan and real articles. |
| SEO landings | Ready in code | Populate and approve real pages; keyword/cannibalization review. |
| AIDA | Not implemented as an automation | Define generation/approval workflow; use n8n to submit drafts rather than publish directly. |
| n8n | Code-ready, deployment pending | Configure production URL/secrets, import or build workflows and enable scheduler. |
| Telegram automation | Code-ready, production blocked | Fix `terra.ai-da.store` DNS/TLS chain, refresh webhook and drain the pending update. |
| PDF presentations | Ready in code | Verify production fonts/images and business template. |
| Multilingual content | Deferred by v0.6 | Introduce locale-aware slugs, translations, hreflang and routing later. |
| 3D / Spatial | Application core ready | Configure production storage/CDN and Blender/reconstruction workers; build the native RoomPlan capture client and verify large real scenes on target devices. |
| Investment direction | Partial domain support | No complete public offer, investment calculations or investor workspace. |
| Payments | Deferred | No production payment provider, orders, callbacks or reconciliation. |
| Tokenization | Deferred | Legacy economy code is not a production real-estate tokenization product. |

## Telegram audit

Local application checks pass:

- Longman loads with the current PHP runtime.
- Account binding, notification queue and automation processor integration test passes.
- Worker completes with an empty queue and no application errors.
- Telegram API confirms the configured webhook URL and accepts `getWebhookInfo`.

Production delivery is not healthy yet. Telegram reported one pending update and
`SSL routines::certificate verify failed` for `https://terra.ai-da.store/tgAdmin_webhook.php` at
2026-08-23 13:31:52 UTC. The host also did not resolve from the local environment. Verify the
public A/AAAA record and install a complete certificate chain trusted by Telegram before calling
`bin/telegram-webhook.php` again.

## What remains for a usable v0.6 release

The core v0.6 workflows are present: objects and groups, media, submissions/moderation, leads,
client cases, manager operations, user roles, catalog, property cards, presentations, internal
listing view and analytics. The release should not be called production-complete until these
items are closed:

1. Deploy all migrations through `20260823_000017_spatial_core.sql` to staging and production with a tested backup/rollback procedure.
2. Fix production DNS/TLS for Telegram, register the webhook, enable worker schedules and verify a real `/start`, task notification and manager digest.
3. Configure the actual n8n production webhook and secrets, test both directions and add alerting for failed outbox deliveries.
4. Run role-based acceptance testing for admin, manager and public user across object creation, media, moderation, lead-to-case and content workflows.
5. Add application-wide CSRF protection and rate limits for public forms/login; review session cookie and reverse-proxy HTTPS settings.
6. Confirm production media limits, storage persistence, backups, image processing resources and PDF generation with real files.
7. Add automated regression coverage for catalog filters, property workflow transitions, media upload and lead/client-case conversion.
8. Complete mobile and browser visual QA after the planned redesign, including empty/error/loading states and accessibility basics.
9. Configure production monitoring for PHP errors, failed queues, webhook failures, disk usage and database backups.
10. Load approved catalog and editorial content, verify sitemap/robots/canonical URLs on the production hostname and connect search analytics.

Items explicitly outside the current v0.6 focus remain multilingual support, native 3D capture/reconstruction infrastructure, advanced MLS,
investment cabinets, payments and tokenization. They should not block the operational sales core.

## Verification commands

```bash
php tests/integration/telegram_automation.php
php tests/integration/content_n8n.php
php tests/integration/content_http.php
php bin/telegram-worker.php --limit=5
php bin/telegram-health.php
php bin/integration-worker.php --schedule-content --limit=5
php tests/integration/spatial_module.php
php bin/spatial-worker.php --limit=10
npm run build:spatial
```
