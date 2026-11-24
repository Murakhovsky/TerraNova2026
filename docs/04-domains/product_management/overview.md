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
capabilities: 28 manifest declarations; approved Shopify Action includes native MySQL E2E
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

## E7/P0: робочий простір каталогу товарів

Human manager at `/product-management` (only when the `product_management`
module is enabled for this tenant). Product and Category navigation is a
module-contributed WebExtension, not a global hard-coded menu. The workspace
supports bounded SKU/name search, Product create/update, Variant create,
Category hierarchy create/assign, UK/EN Product translations and UAH base
price changes. All queries and writes invoke the **existing canonical
Capability Gateway** with server-side TenantContext, manager permissions,
input schemas, CSRF, operation idempotency and Domain readback. No direct SQL
or new executor, no Shopify/stock imports or Sales calls.

This is the E5 catalog slice only, **not** a claim that Inventory Operations,
Product Media UX, CSV approvals, full UI acceptance or production activation
are complete. Product remains off by default; UI requires the tenant to
activate the module explicitly.

## E7/P0: складський робочий простір

`/product-management/stock`: tenant-гейт, лише менеджер, чинні
`location.search`, `product.search`, `stock.balance.get`,
`stock.movement.search` через Gateway. Оператор створює склади
(`location.create`) та виконує лише задокументовані приходи,
витрати і атомарні переміщення (`stock.receive/issue/transfer`).
Кожна команда потребує CSRF і окремий idempotency key, а результат
перевіряється повторним native balance read. Показуються on_hand,
reserved та available із COS ledger, не як Shopify live availability.

Резервування, stock count/adjustments, Sales/Procurement ports, експорт,
bulk/reconciliation та повний UI acceptance ще не входять у цей блок.

## E7/P0: робочий простір CSV-імпорту

`/product-management/import` доступний лише активному Product Management
tenant-manager. Оператор вставляє CSV до 12 000 символів
(`sku,name,type,uom,track_inventory`) у незмінний Preview. Потім
переглядає `import.batch.get`: `digest`, `rows_digest`, помилки
і дублікати SKU. Створення native Product під час Preview **заборонене**.

Коли всі рядки `ready`, оператор може запропонувати Goal/Plan через
наявний `ProductImportPlanProposalService::propose`. Цей сервіс
створює виключно Plan; **не** схвалює його, не створює Action,
не запускає Workflow і не викликає native Product writer. Незалежні
Plan/Action Approval, запуск і evidence виконуються в canonical
`/workspace/goals`. Ніякого direct CSV apply у UI немає.
