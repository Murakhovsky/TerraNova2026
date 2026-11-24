---
title: Product Management — паспорт домену COS
description: Власність, компоненти, бізнес-контракти та обмеження production readiness.
status: draft
updated: 2026-11-20
kind: domain
contract: domain-v1
domain_id: product_management
---

# Паспорт домену Product Management

<!-- COS-DOMAIN-PASSPORT-DATA
{
  "schema_version": 1,
  "domain_id": "product_management",
  "manifest": "app/Domains/ProductManagement/module.php",
  "components": [
    {
      "id": "catalog",
      "capability_ids": [
        "product_management.product.create",
        "product_management.product.get",
        "product_management.product.search",
        "product_management.variant.create",
        "product_management.category.create",
        "product_management.media.attach",
        "product_management.product.update",
        "product_management.product.archive",
        "product_management.category.assign",
        "product_management.category.search",
        "product_management.variant.update",
        "product_management.variant.archive",
        "product_management.translation.upsert",
        "product_management.media.detach",
        "product_management.media.search"
      ]
    },
    {
      "id": "inventory",
      "capability_ids": [
        "product_management.location.create",
        "product_management.location.search",
        "product_management.stock.balance.get"
      ]
    },
    {
      "id": "movements",
      "capability_ids": [
        "product_management.stock.receive",
        "product_management.stock.issue",
        "product_management.stock.transfer",
        "product_management.stock.movement.search"
      ]
    },
    {
      "id": "pricing",
      "capability_ids": [
        "product_management.price.base.set",
        "product_management.price.base.get",
        "product_management.price.history.search",
        "product_management.price.list.create",
        "product_management.price.list.search",
        "product_management.price.list.entry.set",
        "product_management.price.list.entry.get"
      ]
    },
    {
      "id": "integration",
      "capability_ids": [
        "product_management.import.preview",
        "product_management.import.apply",
        "product_management.shopify.import.apply",
        "product_management.sync.status.get",
        "product_management.import.batch.get"
      ]
    }
  ],
  "process_ids": [],
  "readiness": {
    "level": "UNKNOWN",
    "evidence": []
  }
}
COS-DOMAIN-PASSPORT-DATA -->

> **DAS-001.** Внутрішні компоненти належать одному Domain. CI-acceptance не підтверджує production, live Shopify OAuth або завершення Unified Workspace.

## 1. Ідентичність

**Domain ID:** `product_management`. **Власник:** COS Product Management. Manifest: `app/Domains/ProductManagement/module.php`. Tenant-disabled by default; activation і release sign-off не підтверджені.

## 2. Межі відповідальності

Owns Product, Variant/SKU, Categories, Media references, Locations, Stock Ledger, Balances, BasePrice, CSV staging, Shopify observations, previews і GID mappings. Sales, Procurement, Finance, Property, checkout та Shopify write-back не входять до цього Domain.

## 3. Внутрішні компоненти

| Компонент | Відповідальність | Engineering evidence |
| --- | --- | --- |
| Catalog | Product, Variant, Category, Media | MySQL |
| Inventory | Locations та balances | MySQL |
| Movements | Ledger та stock transfer | MySQL |
| Pricing | BasePrice та history | MySQL |
| Integration | CSV, Shopify read-only, approved import, conflicts | Mock/MySQL; live canary pending |

Це функціональна декомпозиція, не незалежні modules або executors.

## 4. Бізнесова модель

Внутрішні Product/Variant IDs відокремлені від Shopify GIDs. Stock balance в COS має бути підтверджений ledger. Shopify inventory є external observation, не автоматичним native Stock movement. `shopify_authoritative` забороняє незалежні локальні зміни mapped товарів до контрольованого ownership cutover.

## 5. Use Cases та Capabilities

Manifest і `resources/contracts/product-management/` є джерелами контрактів. `product_management.shopify.import.apply` вимагає approval та native receipts. Перелік capabilities не дорівнює дозволу на production.

## 6. Виконання

Read-only Shopify snapshot → immutable preview → Federation Goal/Plan → independent Plan Approval → Platform Workflow → approved Kernel Action → native Product/Variants/Prices → run-linked verified Goal Outcome. Другого workflow executor немає.

## 7. Міждоменні контракти

Sales/Procurement/Finance/Property користуються опублікованими capabilities і events. Пряме SQL-оновлення чужих Domain tables заборонено.

## 8. Процеси та результати

Статус у Process Registry: `exempt`, із відкладеним formal business process для керованого sync та advanced stock workflows. `process_ids=[]` є актуальним registry mapping, а не оцінкою обсягу функціоналу.

## 9. Tenant, Policy та Security

Tenant isolation у Domain/Kernel. COS Integration Vault керує Shopify credentials. Вхідні webhooks проходять raw-body HMAC та Inbox deduplication. Manifest сам не надає permission.

## 10. Робочий простір Unified Workspace

Повний Product/Inventory/Integration Workspace, UI approval та UX-E2E залишаються окремим acceptance gate. Операторський CLI не замінює human UI.

## 11. Operations та Recovery

Невідомий результат partial native import переводить Action preview у `blocked`. Read-only audit збирає Product, Variant, Price, mapping та receipt evidence. Ні автоматичний replay, ні пряме редагування даних не допускаються.

## 12. Готовність і докази

**Production readiness: UNKNOWN.** Mock/MySQL CI tests не є live Shopify canary або tenant acceptance. Для D3–D5 потрібні точний commit SHA, tenant, середовище, бізнес-сценарій, час, URL evidence і native readback.

## 13. План розвитку, борги та рішення

E4: live Shopify OAuth/scopes canary, cursor/freshness/throttle, independent recovery soak, approval UI та sign-off. E5 advanced stock; E6 guarded Shopify write-back після окремого рішення власника.

## 14. Карта джерел

- Manifest: `app/Domains/ProductManagement/module.php`
- Domain: `app/Domains/ProductManagement/`
- Overview: `docs/04-domains/product_management/overview.md`
- Architecture: `docs/11-decisions/ADR-0016-product-management-domain.md`
- Contracts: `resources/contracts/product-management/`
- CI: `.github/workflows/runtime.yml`
- References: `docs/12-reference/module-capabilities.md`
