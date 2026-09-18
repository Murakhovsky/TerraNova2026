---
title: Перехід вертикальними зрізами та сумісність
description: Канонічний підхід COS до тестування, legacy adapters, strangler migration, frontend compatibility та Symfony Console.
status: active
updated: 2026-09-18
kind: architecture
contract: architecture-v1
---

# Перехід вертикальними зрізами та сумісність

## 32. Тестування

COS уже має велику мережу unit, smoke, architecture та deployment contracts. Поточна міграція не вводить другий тестовий світ лише заради PHPUnit-етикетки. Кожен Symfony slice зобов'язаний мати:

- чистий Application/Domain contract test;
- architecture boundary test;
- Docker integration test для реального adapter wiring;
- deployment smoke для змін runtime.

## 33. Межа сумісності legacy

Legacy база не стає Domain API. Symfony composition root явно зв'язує legacy read-only PDO лише з Infrastructure adapters.

Новий HTTP або Console код не може бачити `PDO`, SQL або `legacy_cos.pdo`.

## 34. Перехід методом strangler

Перший бізнесовий Symfony slice після системних probes:

```text
GET /api/v1/sales/client-cases/stats
        ↓
SalesClientCaseStatsController
        ↓
QueryBus
        ↓
GetClientCaseStatsQueryHandler
        ↓
ClientCaseReadModelFactoryInterface
        ↓
MysqlClientCaseReadModel
        ↓
legacy MySQL (read-only)
```

Tenant береться із `TenantContext`, а не з query parameter або глобальної session-змінної.

## 35. Сумісність frontend

Існуючий frontend не переписується. Новий versioned endpoint додається паралельно; старий UI може переходити на нього окремим slice без одночасної зміни URL, payload і persistence.

## 36. Консольні команди

Symfony Console стає канонічною точкою для нових operational commands. `cos:sales:client-case-stats --organization=...` використовує той самий QueryBus і той самий Application handler, що й HTTP. CLI не отримує окремої бізнес-логіки.

Старі Phalcon CLI tasks залишаються compatibility entrypoints до моменту перенесення відповідних services у Symfony composition.
