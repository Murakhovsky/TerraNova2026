---
title: Property Domain Overview
description: Поточні boundaries, ports, use cases та persistence Property domain.
status: active
updated: 2026-09-11
kind: domain
---

# Property Domain Overview

Property — bounded context для real-estate catalogue та operational lifecycle об'єктів.

## Поточна структура

```text
Property/
├── Model
├── Application
│   ├── Contract
│   ├── Service
│   └── UseCase
├── Infrastructure
│   └── Persistence
└── module.php
```

## Application capabilities

Property має contracts для:

- catalogue;
- management;
- submissions;
- moderation;
- submission media;
- media storage;
- presentation;
- notification;
- analytics;
- funnel analytics;
- location references;
- Sales-facing presentation integration.

Use cases включають submission і moderation; management logic винесена в application service.

## Persistence

MySQL implementations знаходяться в `Infrastructure/Persistence/MySql`.

Legacy Phalcon Telegram models ізольовані під compatibility path. Це не шаблон для нового коду.

## Submission boundary

Submission і canonical Property — різні lifecycle concepts. Moderation є explicit application boundary між intake та publication/management.

## Cross-domain relation із Sales

Property не має напряму залежати від Sales implementation. Для presentation/Sales interaction є explicit contract boundary.

## Analytics

Property contracts вже передбачають general analytics і funnel analytics. Це означає, що tracking має бути domain-aware, але concrete telemetry/storage може залишатися infrastructure concern.

## Module status

Manifest:

- id: `property`;
- version: `0.1.0`;
- kernel constraint: `^0.7.1`;
- enabled by default;
- runtime module service: поки `null`;
- capabilities: поки порожні.

Тобто Domain уже фізично виділений, але ще не доведений до повного Sales-рівня pluggable runtime contract.