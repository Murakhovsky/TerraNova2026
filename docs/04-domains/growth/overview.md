---
title: Огляд домену Growth
description: Канонічний Growth Operating System для пошуку ринку, Opportunity Intelligence, керованої взаємодії, маршрутизації відповідей і навчання за результатами.
status: active
updated: 2026-09-26
kind: domain
contract: domain-v1
---

# Огляд домену Growth

Growth `0.50.0` є виконуваним bounded context COS для **пошуку цінності та створення керованих можливостей**, а не просто маркетинговим модулем.

## Призначення

```text
Market
  ↓
ICP / Account Discovery
  ↓
Signals
  ↓
Research / WHY NOW
  ↓
OpportunityCandidate
  ↓
Qualification / Buying Committee
  ↓
Engagement / Outreach
  ↓
Reply / Routing
  ↓
Sales / Service
  ↓
Outcome Feedback
  ↓
Learning / Experiments / Optimization
```

Growth володіє пошуком і обґрунтуванням можливості, її доказами, керованою взаємодією до handoff та навчанням за зовнішнім результатом. Він не володіє Sales Deal, Service Ticket, Finance revenue recognition або станом delivery інших доменів.

## Декларація середовища виконання

```text
id: growth
version: 0.50.0
schema: 0.50.0
kernel: >=0.11.0 <0.12.0
enabled_by_default: false
```

`enabled_by_default=false` є production gate, а не ознакою відсутнього runtime. Модуль уже має API, SSR workspaces, scheduler handlers, collectors, persistence, domain events, audit та інтеграційні адаптери. Автоматичне ввімкнення відкладається до production cutover із smoke і rollback процедурою.

## Канонічний життєвий цикл

```text
Market Universe
      ↓
Account + immutable intelligence snapshot
      ↓
ICP match
      ↓
canonical Signal
      ↓
OpportunityCandidate
      ↓
evidence-bound Research / WHY NOW
      ↓
deterministic Qualification
      ↓
Buying Committee
      ↓
Next Best Action
      ↓
governed execution / sequence
      ↓
Inbound Response
      ↓
classification
      ↓
authoritative Conversation Routing
      ↓
Sales / Service reference
      ↓
Outcome observation
      ↓
Growth Learning
```

**Signal != Opportunity** є базовим інваріантом. Високий ICP fit або факт із provider не створює OpportunityCandidate без канонічного Signal evidence.

## Пошук ринку

V0.48–V0.50 додали Market Universe та автоматичний пошук Accounts. Перший provider adapter працює через credentialed HTTPS JSON source, Platform Credential Vault і resilience boundary.

Discovery run має:

- tenant-scoped Universe та run state;
- стабільний idempotency receipt;
- expiring database lease для захисту від паралельного повторного provider I/O;
- bounded page size і rejected-row accounting;
- `partial` semantics без просування cursor;
- resumable retry після expired lease;
- safe credential redaction у проєкціях та audit.

## Інтелект і керування рішеннями

Growth використовує governed LLM лише там, де це додає інтелект поверх детермінованої системи: Research, Engagement recommendation, Experiment recommendation, content drafting і inbound response classification.

LLM не отримує прямого права змінювати зовнішні домени. Qualification, activation, capacity admission, routing і handoff authority залишаються детермінованими application/policy boundaries.

## Взаємодія та відповіді

Engagement підтримує email, LinkedIn і call execution через контрольовані adapters, tenant limits, channel quotas, atomic capacity locking, activation policy, delivery feedback та outreach sequences.

Inbound response зберігається як Growth fact, класифікується окремо, а потім проходить Conversation Routing. Routing може створити canonical Sales Lead або Service Request лише через application contract відповідного домену.

## Міждоменні контракти

Growth декларує явні cross-domain contracts із Sales і Service.

```text
Growth
  ├─ SalesWriteServiceFactoryInterface → Sales Lead intake
  ├─ ServiceApplicationBoundary        → Service Request intake
  └─ Sales events / vocabulary         → Outcome Feedback
```

Growth не має права писати в таблиці Sales або Service напряму. Повернене target-domain reference прив’язується до Growth Candidate, після чого зовнішні outcome events формують Growth-owned learning observations.

## Безпека та приватність

- organization identity береться з TenantContext, а не з request payload;
- credentials зберігаються як opaque reference і розкриваються лише через Credential Vault;
- provider adapters блокують private/reserved targets, redirects і unbounded payloads;
- raw contact identity values не передаються в LLM engagement context;
- outreach проходить policy, suppression, quota та capacity guardrails;
- idempotency conflict не маскується повторним виконанням.

Тип ідентичності контакту може використовуватися детерміновано для визначення доступного каналу, але саме значення адреси або профілю не входить у модельний контекст.

## Поточний production gate

V0.50 означає функціонально замкнений Growth runtime, але ще не V1 production cutover. До V1 залишаються:

1. повністю зелений інтеграційний CI на актуальному `main`;
2. install → migrate → configure → enable → smoke acceptance;
3. rollback strategy;
4. явне рішення щодо `enabled_by_default`.

## Пов’язані матеріали

- [Процес Signal → Qualified Opportunity Handoff](../../02-workflows/growth-opportunity-candidate-to-handoff.md)
- [Модулі та можливості](../../12-reference/module-capabilities.md)
- [Події](../../12-reference/event-types.md)
- [Покриття доменів процесами](../../12-reference/domain-process-coverage.md)
- [Зберігання даних, черги та планувальник](../../03-architecture/persistence-async-scheduler.md)
