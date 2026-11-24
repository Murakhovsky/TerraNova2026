---
title: Product Management — паспорт домену COS
description: Власність, компоненти, бізнес-контракти та обмеження production readiness.
status: draft
updated: 2026-11-24
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
        "product_management.attribute.define",
        "product_management.attribute.search",
        "product_management.attribute.category.bind",
        "product_management.attribute.category.get",
        "product_management.attribute.product.set",
        "product_management.attribute.product.bulk_set",
        "product_management.attribute.product.get",
        "product_management.attribute.product.filter",
        "product_management.variant.matrix.preview",
        "product_management.variant.matrix.create",
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
        "product_management.stock.reserve",
        "product_management.stock.count.apply",
        "product_management.stock.count.get",
        "product_management.stock.count.session.apply",
        "product_management.stock.count.session.get",
        "product_management.stock.count.session.candidates",
        "product_management.stock.reservation.search",
        "product_management.stock.release",
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
        "product_management.price.list.entry.get",
        "product_management.price.list.update",
        "product_management.price.list.archive",
        "product_management.price.list.history.search",
        "product_management.price.list.entry.remove",
        "product_management.price.list.tier.set",
        "product_management.price.list.tier.search",
        "product_management.price.list.tier.history.search",
        "product_management.price.resolve"
      ]
    },
    {
      "id": "governed_operations",
      "capability_ids": [
        "product_management.governed.product.create",
        "product_management.governed.variant.matrix.create",
        "product_management.governed.attribute.product.bulk_set",
        "product_management.governed.price.base.set",
        "product_management.governed.stock.receive",
        "product_management.governed.stock.issue",
        "product_management.governed.stock.reserve",
        "product_management.governed.stock.release"
      ]
    },
    {
      "id": "analytics",
      "capability_ids": [
        "product_management.analytics.summary.get",
        "product_management.analytics.stock.search",
        "product_management.analytics.movement.search",
        "product_management.analytics.quality.search",
        "product_management.analytics.reconciliation.get"
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
  "process_ids": [
    "product_management.catalog-to-variants",
    "product_management.csv-to-native-catalog",
    "product_management.governed-business-cycle",
    "product_management.physical-count-to-correction",
    "product_management.pricing-to-quote",
    "product_management.stock-to-reconciliation"
  ],
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
| Analytics | Native read-only assortment, stock and quality metrics | MySQL PASS on b61482c; current HEAD pending |
| Governed operations | Signed intent, canonical Plan and native readback | New E2E pending |
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

Статус у Process Registry: `covered`. Шість канонічних процесів охоплюють каталог, ціноутворення, склад, фізичний перерахунок, CSV та керований AI-цикл. Моделі не є доказом runtime/production acceptance.

## 9. Tenant, Policy та Security

Tenant isolation у Domain/Kernel. COS Integration Vault керує Shopify credentials. Вхідні webhooks проходять raw-body HMAC та Inbox deduplication. Manifest сам не надає permission.

## 10. Робочий простір Unified Workspace

Product/Inventory/Attributes/Pricing/Dashboard Workspace та review intents реалізовані; повне UI approval та UX-E2E залишаються acceptance gate. Операторський CLI не замінює human UI.

## 11. Operations та Recovery

Read-only E5 Stock audit звіряє on_hand з ledger і reserved з активними резервами; DRIFT або INCOMPLETE не є readiness. Невідомий результат partial native import переводить Action preview у `blocked`. Read-only audit збирає Product, Variant, Price, mapping та receipt evidence. Ні автоматичний replay, ні пряме редагування даних не допускаються.

## 12. Готовність і докази

**Production readiness: UNKNOWN.** Mock/MySQL CI tests не є live Shopify canary або tenant acceptance. Для D3–D5 потрібні точний commit SHA, tenant, середовище, бізнес-сценарій, час, URL evidence і native readback.

## 13. План розвитку, борги та рішення

Native release: all CI at candidate SHA, actual browser and independent approval acceptance, live agent/LLM test, explicit operator sign-off. Remaining AI coverage: some human-only lifecycle, media, list-price and transfer mutations have no governed equivalent; no dynamic output substitution. Optional Shopify: independent live OAuth/scopes canary and recovery; write-back only after separate ownership decision.

## 14. Карта джерел

- Manifest: `app/Domains/ProductManagement/module.php`
- Domain: `app/Domains/ProductManagement/`
- Overview: `docs/04-domains/product_management/overview.md`
- Architecture: `docs/11-decisions/ADR-0016-product-management-domain.md`
- Contracts: `resources/contracts/product-management/`
- CI: `.github/workflows/runtime.yml`
- References: `docs/12-reference/module-capabilities.md`
