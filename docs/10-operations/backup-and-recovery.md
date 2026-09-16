---
title: Резервне копіювання та відновлення
description: Обсяг backup, перевірка restore і цілі відновлення для durable state COS.
status: active
updated: 2026-09-16
kind: operations
---

# Резервне копіювання та відновлення

Backup існує лише тоді, коли restore був перевірений. До цього це файл із дуже оптимістичною назвою.

## Що потрібно класифікувати

Для кожного durable store визначте:

- authoritative чи derived;
- backup method;
- retention;
- encryption/access;
- restore procedure;
- прийнятні RPO/RTO;
- dependency order під час recovery.

## Authoritative і rebuildable дані

Canonical Domain state, audit/history, configuration references і critical operational ledgers мають вищий recovery priority.

Search indexes, caches, generated projections та інші rebuildable artifacts можуть відновлюватися з authoritative sources, якщо rebuild path реально існує і протестований.

## Послідовність відновлення

```text
Визначити recovery point
→ restore authoritative data
→ validate schema/version
→ restore/reconcile operational ledgers
→ rebuild derived projections/indexes
→ start runtime/workers
→ run integrity + business smoke checks
→ reopen traffic
```

## Events, Outbox і queues

Recovery має враховувати Outbox/inbox/queue state, щоб після restore:

- не втратити pending work;
- не виконати consequential side effect двічі;
- не позначити operation успішною лише через наявність старого запису в projection;
- зберегти correlation/audit history там, де вона потрібна для reconciliation.

Idempotency protection є частиною recovery design, а не бонусною функцією integration layer.

## Перевірка restore

Періодично перевіряйте:

- backup readability;
- restore в isolated environment;
- migration/version compatibility;
- ключові table/count/invariant checks;
- critical use-case smoke;
- worker/queue readiness;
- measured restore duration.

Без виміряного restore duration RTO лишається побажанням, записаним серйозним шрифтом.

## Секрети

Backup application data не повинен неявно бути backup secret store. Secrets/configuration мають власний recovery process, access policy і rotation procedure.

## Пов’язані сторінки

- [Дані та міграції](./data-and-migrations.md)
- [Розгортання та перевірка стану](./deployment-and-health.md)
- [Операційна безпека](./security-operations.md)
