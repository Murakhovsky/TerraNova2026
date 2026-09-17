---
title: Огляд домену Procurement
description: Межа V1 домену Procurement для постачальників, заявок на закупівлю, пропозицій, замовлень і поставок.
status: active
updated: 2026-09-17
kind: domain
contract: domain-v1
---

# Огляд домену Procurement

Procurement `0.1.0` є **канонічним V1 skeleton-domain** COS. Він фіксує предметну модель закупівель та Application boundary без передчасного persistence або orchestration runtime.

## Призначення

```text
Supplier
   ↓
PurchaseRequest → Quote → Order → Delivery
```

Канонічні моделі: `Supplier`, `PurchaseRequest`, `Quote`, `Order`, `Delivery`.

## Поточний стан

```text
id: procurement
version: 0.1.0
runtime: disabled
persistence: none
routes: none
process model: explicitly deferred
```

Marketplace, ERP та supplier API не входять у Domain. Вони мають підключатися через Platform Integration adapters. Відсутність executable process у V1 є explicit architecture exemption до початку реалізації Procurement runtime.

- [Модулі та capabilities](../../12-reference/module-capabilities.md)
- [Покриття доменів процесами](../../12-reference/domain-process-coverage.md)
