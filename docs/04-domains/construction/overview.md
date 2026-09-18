---
title: Огляд домену Construction
description: Межа V1 домену Construction для проєктів, майданчиків, кошторисів, підрядників, робіт, матеріалів, етапів та інспекцій.
status: active
updated: 2026-09-18
kind: domain
contract: domain-v1
---

# Огляд домену Construction

Construction `0.1.0` фіксує канонічний предметний словник майбутнього будівельного runtime COS без передчасного persistence або automation.

## Призначення

```text
Project → Site / Object → Work → Milestone → Inspection
   ├─ Estimate
   ├─ Contractor
   └─ Material
```

PHP-клас `ConstructionObject` представляє бізнес-поняття `Object`, щоб не використовувати зарезервоване мовою ім'я.

## Поточний стан

```text
id: construction
version: 0.1.0
runtime: disabled
persistence: none
routes: none
process model: explicitly deferred
```

Домен великий за потенційним scope, але його runtime навмисно не є блокером Symfony migration.

## Межі

Construction володіє виконанням будівельного проєкту. Закупівлі належать Procurement, фінансові проводки — Finance, документи — Platform Documents, а фізичний real-estate asset після появи як об'єкта нерухомості належить Property.
