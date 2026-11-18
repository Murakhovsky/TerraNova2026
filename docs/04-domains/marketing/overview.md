---
title: Огляд домену Marketing
description: Кампанії, Paid Media, Meta Ads і доказова аналітика.
status: draft
updated: 2026-11-18
kind: domain
contract: domain-v1
---

# Marketing Domain

Marketing володіє маркетинговими кампаніями та планами. Meta Ads є адаптером Paid Media. Жодного дублювання Growth, Product, Sales або Finance.

## Реалізований зріз

M0: module discovery, tenant-gated draft creation/search, campaign revision, idempotency, domain outbox, public capability contracts і Workspace scaffolding.

M1 code foundation: Meta GET-only transport, read-only account verification, імпорт Campaign/AdSet/Ad, щоденні campaign-grain Insights із source hash, checkpoint і currency. Meta spend є provisional observation, а не бухгалтерською витратою.

## Обмеження

Live Meta OAuth/App Review, Vault setup, external sandbox reconciliation, CI, worker scheduling, UX acceptance ще не підтверджені. D1 не означає production-ready. Finance F1, M3 paid mutations і атрибуція M4 залишаються окремими пакетами.

Детальні межі: [ADR-0017](../../11-decisions/ADR-0017-marketing-domain-boundary.md).
