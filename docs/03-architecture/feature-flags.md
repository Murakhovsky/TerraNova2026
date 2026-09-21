---
title: Платформа керованого розгортання функцій
description: Канонічна модель feature flags для поступового розгортання можливостей COS без дублювання module activation, authorization або tenant policy.
status: active
updated: 2026-09-21
kind: architecture
---

# Платформа керованого розгортання функцій

Wave 12.20 створює progressive rollout foundation для COS.

Feature flag відповідає лише на питання:

> чи дозволено вже показувати або виконувати конкретну нову поведінку для цього стабільного контексту?

Він не відповідає на питання:

- чи модуль встановлений;
- чи модуль active/ready;
- чи користувач має permission;
- чи resource належить tenant;
- чи Command дозволений policy;
- чи workflow transition допустимий.

## Відмінність від активації модулів

Модель навмисно розділена:

```text
ModuleCatalog / ActiveModuleResolver
        ↓
capability існує для tenant?
        ↓
FeatureFlagResolver
        ↓
нова поведінка вже розгорнута?
        ↓
Authorization / Policy / Command guards
        ↓
execution
```

Feature flag ніколи не активує модуль і ніколи не обходить permission.

## Канонічна сутність

Definition містить:

```text
flag key
master enabled
rollout percentage
rollout salt
optional start
optional end
description
```

Master `enabled=false` є kill switch і має найвищий пріоритет.

## Контекст

`FeatureFlagContext` містить:

- organization id;
- optional user id;
- surface.

Surface використовується як контекст рішення, але не входить у rollout subject.

Тому той самий user не повинен випадково отримати feature у Web і втратити його в PWA/native лише через іншу presentation surface.

Stable rollout subject:

```text
organization:user
```

або для organization-level контексту:

```text
organization:organization
```

## Детерміноване розгортання

Allocation не використовує random.

```text
sha256(
    flag_key
    + rollout_salt
    + stable_subject
)
        ↓
bucket 0..9999
        ↓
bucket < rollout_percentage * 100
```

Таким чином 10% означає 1000 стабільних buckets із 10000.

Зміна page reload, process або surface не змінює assignment.

Зміна salt є свідомим reshuffle і повинна розглядатися як configuration change.

## Перевизначення

Пріоритет:

```text
master disabled
      ↓
user override
      ↓
organization override
      ↓
schedule window
      ↓
percentage rollout
```

Override може мати expiration.

User override має вищий пріоритет за organization override.

Це дозволяє:

- internal dogfood;
- pilot users;
- canary tenants;
- emergency disable для конкретної organization;
- тимчасовий support workaround.

## Безпечна поведінка за замовчуванням

Невідомий flag завжди:

```text
enabled = false
reason = unknown_flag
```

Немає implicit “true”, environment guessing або frontend default.

## Зберігання

Platform володіє:

```text
cos_feature_flags
cos_feature_flag_overrides
```

Definition глобальна для deployment.

Overrides scoped через:

```text
organization_id
subject_type
subject_id
```

Tenant/user context для Web adapter береться лише з server-side `TenantContextProviderInterface`.

Client не може передати organization id у query/body і сам собі ввімкнути feature.

## Використання

Shared Application/API/Agent code може інжектити:

```text
FeatureFlagResolver
```

Web adapter використовує:

```text
WebFeatureFlags
```

Типова перевірка:

```text
module active?
   ↓
feature enabled?
   ↓
permission allowed?
   ↓
Command / Query
```

## Чого не робити

Не використовувати feature flags як:

- authorization;
- billing entitlement;
- module lifecycle;
- permanent business configuration;
- schema migration switch;
- tenant identity;
- security boundary.

Не розміщувати business semantics конкретного Domain у shared resolver.

## Подальший розвиток

Поза foundation лишаються:

- admin UI для керування flags;
- approval workflow для production rollout;
- audit history configuration changes;
- metrics по exposure/outcome;
- multivariate experiments;
- automatic rollback за SLO;
- stale-flag cleanup lifecycle.

Ці можливості можуть бути додані поверх стабільних contracts без зміни Domain/Web architecture.
