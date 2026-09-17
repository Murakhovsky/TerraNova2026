---
title: Огляд домену Finance
description: Межа V1 домену Finance для рахунків, транзакцій, інвойсів, платежів, бюджетів, витрат і доходів.
status: active
updated: 2026-09-17
kind: domain
contract: domain-v1
---

# Огляд домену Finance

Finance `0.1.0` є **канонічним V1 skeleton-domain** COS. На цьому етапі він задає бізнес-словник і Application contracts, але не вводить бухгалтерський runtime, persistence чи платіжні інтеграції.

## Призначення

```text
Account
├─ Transaction
├─ Invoice → Payment
├─ Budget
├─ Expense
└─ Revenue
```

Канонічні моделі: `Account`, `Transaction`, `Invoice`, `Payment`, `Budget`, `Expense`, `Revenue`.

## Поточний стан

```text
id: finance
version: 0.1.0
runtime: disabled
persistence: none
routes: none
process model: explicitly deferred
```

Відсутність executable process у V1 зафіксована explicit architecture exemption, а не прихована як випадковий борг.

## Межі

Finance не виконує платежі й не знає про конкретні банки, PSP або ERP. Майбутні зовнішні системи повинні входити через Platform Integration, а persistence і orchestration з'являться тільки разом із окремим Finance runtime slice.

- [Модулі та capabilities](../../12-reference/module-capabilities.md)
- [Покриття доменів процесами](../../12-reference/domain-process-coverage.md)
