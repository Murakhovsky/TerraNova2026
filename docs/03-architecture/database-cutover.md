---
title: Перехід operational database
description: Поступове перенесення COS з legacy MySQL до єдиної canonical Symfony/COS MySQL schema.
status: active
updated: 2026-09-20
kind: architecture
contract: architecture-v1
---

# Перехід operational database

Symfony runtime уже став канонічним, але на старті цього етапу 86 сервісів у composition root напряму використовували `legacy_cos.pdo`. Це означає, що legacy MySQL фактично залишався operational source of truth.

Ціль не змінювати MySQL. Ціль — одна canonical MySQL schema, керована Doctrine migrations, при цьому стабільні MySQL repositories не переписуються лише заради іншого persistence API.

## Канонічне підключення

`cos.database.pdo` отримує native PDO з тієї самої Doctrine DBAL connection, що використовує `DATABASE_URL`.

```text
DATABASE_URL
    ↓
Doctrine DBAL
    ↓
cos.database.pdo
    ↓
existing MySQL repositories
```

Нові залежності від `legacy_cos.pdo` забороняє architecture gate. Початковий список заморожений і може лише скорочуватися.

## Порядок хвиль

Міграція йде transactional table clusters, а не за назвами framework-класів:

1. module/runtime та незалежні Platform stores;
2. Identity/Tenant і configuration/integration credentials;
3. Diagnostic;
4. Documents, Service, RealEstate;
5. Property canonical asset/inventory/listing та видалення compatibility projections;
6. Sales + CRM integrations;
7. Kernel Event/Rule/Policy/Action/Audit/Queue і фінальний global transaction boundary.

Для write use case не можна перемкнути лише repository, якщо TransactionManager/EventBus залишаються на іншій фізичній базі. Усі учасники однієї atomic transaction мають переходити разом.

## Критерій завершення

Cutover завершений лише коли `legacy_cos.pdo` зникає з service graph, deploy більше не створює legacy DML user, Symfony containers не потребують legacy database network, усі canonical tables створюються Doctrine migrations, а legacy MySQL можна зупинити без деградації HTTP, workers, scheduler, integrations чи agent runtime.

## Міст для compatibility runtime

Після Wave 0 лишається принципова проблема: частина старого PHP runtime ще реально виконує бізнес-код. Для активних таблиць простого `copy → switch Symfony` недостатньо, бо compatibility процес може продовжити писати у legacy schema.

Wave 1 foundation додає окреме підключення `canonicalDatabaseService` у старий composition root:

```text
legacy PHP runtime
      │
      ├── databaseService ─────────────→ legacy MySQL
      │
      └── canonicalDatabaseService ───→ cos_symfony
                                        canonical MySQL
```

На цьому етапі жоден бізнес-сервіс ще не використовує `canonicalDatabaseService`. Architecture gate це фіксує. Наступні хвилі явно переключатимуть лише сервіси одного transactional cluster.

Canonical MySQL підключається до `cos_backend` вручну під collision-safe alias `cos-symfony-canonical-mysql`. Compose service alias `mysql` тут не використовується, бо в shared network він уже належить legacy MySQL.

Compatibility account `cos_compat_app` має лише `SELECT, INSERT, UPDATE, DELETE` на `cos_symfony`. Пароль детерміновано похідний від існуючого legacy DB secret, тому retired runtime не отримує окремий plaintext secret. Deploy перевіряє міст реальним `SELECT 1` із legacy PHP container.

Цей міст дозволяє під час наступної active-cluster wave зупинити writers, зробити final snapshot/copy, а потім одночасно перевести Symfony і compatibility writers на canonical DB без dual-write race.
