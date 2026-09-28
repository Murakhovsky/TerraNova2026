---
title: Growth production cutover
description: Керована активація Growth V0.50 для tenant, production smoke, live Sales golden path acceptance та rollback.
status: active
updated: 2026-09-28
kind: operations
---

# Виробниче введення Growth

Ця процедура переводить Growth із **deployed capability** у **production-active capability для конкретної organization**. Вона не змінює `enabled_by_default` глобально і не трактує deployment як доказ працездатності.

## Виробничий контракт

Початковий стан:

```text
Growth code deployed
schema 0.50.0 deployed
enabled_by_default=false
tenant Growth disabled
```

Цільовий стан:

```text
migrations ready
    ↓
tenant activation
    ↓
basic production smoke
    ↓
one real Sales golden path
    ↓
persisted live evidence verified
    ↓
production acceptance recorded
```

Production-ready тут означає, що Growth можна керовано активувати для tenant, довести повний live цикл і так само керовано вимкнути без руйнування schema або evidence.

## Чому типове ввімкнення лишається вимкненим

`enabled_by_default=false` є safety policy для multi-tenant runtime. Новий deployment не повинен тихо запускати Market discovery, outreach або routing для всіх organizations.

Після production acceptance Growth вмикається явно для потрібних tenants. Переведення `enabled_by_default` у `true` є окремим продуктовим рішенням, а не технічним критерієм готовності.

## Передумови

Перед cutover мають бути виконані всі умови:

- production працює на конкретному commit, який пройшов CI;
- deployment migrations застосовані; application runtime не виконує schema mutations під час activation;
- MySQL backup/restore point або еквівалентна recovery гарантія існує;
- Symfony runtime, DB, queue, outbox, Messenger workers і Scheduler bootable та healthy;
- Sales runtime готовий приймати Growth handoff / Conversation Routing;
- Growth webhook secrets, provider credential references і потрібні зовнішні інтеграції налаштовані;
- автоматичні Growth schedulers на початку canary залишаються OFF, якщо їх явне ввімкнення не входить у затверджений rollout;
- визначений реальний canary account/prospect, на якому допустима фактична business interaction.

## 1. Попередня перевірка

```bash
php bin/console cos:growth:cutover status --organization=<org>
```

До activation нормальним результатом може бути `DISABLED`. Критичними blockers є missing migrations, schema status unavailable, version drift або помилка read model.

## 2. Активація організації

```bash
php bin/console cos:growth:cutover enable \
  --organization=<org> \
  --actor=<user-id> \
  --reason="Growth V0.50 production canary" \
  --confirm=ENABLE_GROWTH
```

Команда використовує canonical Module lifecycle. Якщо tenant ще не має explicit installation state, виконується install+enable; якщо Growth уже installed, виконується enable.

Після activation команда негайно запускає basic smoke. Якщо basic smoke fail, Growth автоматично вимикається для organization і повертається non-zero exit code.

Basic smoke перевіряє module readiness = `READY`, active/current state, відсутність missing Growth migrations і доступність Growth workspace read model.

## 3. Живий еталонний цикл через Sales

Production acceptance використовує **реальні persisted business facts**, а не fixture і не synthetic SQL insertion.

Canary має пройти шлях:

```text
Market Universe
  → discovered Account
  → ICP match
  → canonical Signal
  → OpportunityCandidate
  → Buying Committee assessment
  → accepted Engagement recommendation
  → governed execution
  → real inbound Reply
  → deterministic route / handoff to Sales
  → Sales lifecycle event
  → Growth Outcome
  → Learning
```

Для V1 cutover authoritative golden path закінчується в **Sales**, тому що поточний durable outcome-feedback consumer нормалізує Sales lifecycle events у Growth Learning. Service routing є окремою executable capability, але не замінює Sales golden-path acceptance, доки Service outcome-feedback contract не стане таким самим явним.

Не створювати outcome, route або reply прямим SQL лише для того, щоб smoke став зеленим.

## 4. Перевірка на живих даних

Після завершення canary business cycle взяти canonical Candidate id:

```bash
php bin/console cos:growth:cutover verify \
  --organization=<org> \
  --candidate=<GCND-id>
```

Live verification є read-only і вимагає evidence для того самого Candidate:

```text
target_domain = sales
Market membership > 0
Signal evidence > 0
Account exists
Buying Committee assessment > 0
Accepted recommendation > 0
Governed execution > 0
Inbound response > 0
Reply learning outcome > 0
Sales route or accepted Sales handoff > 0
Sales outcome feedback > 0
Terminal business outcome > 0
```

Acceptance = command exit code `0` і кожен check має `ok=true`.

JSON output зберігається разом із deployment commit SHA, organization id, Candidate id та correlation ids lifecycle operations як release evidence.

## 5. Спостереження після прийняття

Після live verify перевірити queue/outbox, retries, Market cursor behavior, webhook idempotency, Growth → Sales learning bindings та відсутність secret/cross-tenant leakage у logs/audit. Автоматичні schedulers вмикаються по одному після canary.

## Відкат

```bash
php bin/console cos:growth:cutover rollback \
  --organization=<org> \
  --actor=<user-id> \
  --reason="<incident/change reference>" \
  --confirm=DISABLE_GROWTH
```

Rollback вимикає Growth для конкретної organization, але не видаляє installation, schema, evidence, outcomes, audit або idempotency receipts.

Якщо schedulers були ввімкнені через environment, їх також треба вимкнути у deployment configuration і перезапустити відповідний Scheduler/worker runtime. Pending queue/outbox records спочатку інспектуються; non-idempotent external actions не replay-яться без перевірки operation identity.

Code rollback дозволений лише до commit, сумісного з уже застосованою schema. Production migration вважається immutable; recovery не базується на down-migration.

## Рішення про запуск

**GO**: basic smoke green, один реальний Sales golden path green, queues/outbox healthy, rollback command перевірений, release evidence збережене.

**NO-GO**: missing migration/version drift, failed live evidence check, duplicate external effect, unresolved queue poison, broken Sales binding/outcome feedback або неможливість tenant-level disable.

## Пов’язані матеріали

- [Growth Domain](../04-domains/growth/overview.md)
- [Готовність модулів](./module-readiness.md)
- [Розгортання та health](./deployment-and-health.md)
- [Дані та migrations](./data-and-migrations.md)
