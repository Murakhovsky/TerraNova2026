---
title: Огляд домену управління товарами
description: Призначення, межі, дані та обмеження пілотної реалізації домену Product Management у COS.
status: active
updated: 2026-11-19
kind: domain
contract: domain-v1
---

# Огляд домену управління товарами

Домен Product Management об'єднує товарний каталог, варіанти товарів,
базові ціни, складські залишки та облік рухів. Зовнішні торгові платформи
взаємодіють із ним через адаптери.

## Призначення

- **Товари:** назва, тип, статус, SKU та варіанти.
- **Склади:** локації, баланси, надходження й списання.
- **Ціни:** базова ціна за варіантом і валютою, історія зміни.
- **Імпорт:** незмінне CSV staging, окремий approved Kernel Action і native reconciliation.
- **Медіа:** безпечні Product/Variant-зв'язки з існуючими tenant-owned файлами COS.
- **Зовнішні джерела:** Shopify GraphQL лише для читання,
  окремі знімки залишків, контроль зовнішніх ідентифікаторів.

## Поточний стан

```text
id: product_management
version: 0.1.0
schema_version: 0.8.0
runtime: tenant-disabled by default
persistence: E1–E4 migrations proposed, not production-verified
capabilities: 27 executable declarations pending integration acceptance
Shopify: read-only; no outbound writes
process model: canonical Goal/Plan Approval Workflow Action and Goal outcome MySQL E2E passed
```

Код E0–E4 у чернетці PR, не вважається завершеним або перевіреним
у production. Підтвердження результату потребує MySQL integration,
Symfony container, capability invocation і Shopify sandbox smoke.

## Межі відповідальності

Product Management є власником `pm_*` і не змінює безпосередньо
таблиці Sales, Procurement, Finance або Property. Товарний облік
не тотожний обліку об'єктів нерухомості Property.

Кожна складська мутація додає рядок незмінного журналу рухів,
оновлює баланс у тій самій транзакції і створює Domain Event через
наявний канонічний Outbox. База Shopify залишається окремим
джерелом спостережень. Жоден зовнішній snapshot не перетворюється
на внутрішній складський рух без підтвердженої операції.

## Пов'язані матеріали

- [Каталог модулів і можливостей](../../12-reference/module-capabilities.md)
- [Покриття бізнес-процесами](../../12-reference/domain-process-coverage.md)
- [Архітектурне рішення](../../11-decisions/ADR-0016-product-management-domain.md)
