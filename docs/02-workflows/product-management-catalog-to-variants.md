---
title: Product Catalog → Variant Matrix
description: Канонічна модель бізнес-процесу Product Management.
status: draft
updated: 2026-11-24
kind: workflow
contract: workflow-v2
process_state: as-is
process_id: product_management.catalog-to-variants
---

# Product Catalog → Variant Matrix

## Бізнес-мета

- Native Product and SKU exist
- Typed category characteristics are readable
- Previewed unique combinations are created without changing existing SKU

## Учасники

- Product manager

## Тригер

New local assortment requires structured SKU identities.

## Процес

<ProcessDiagram process-id="product_management.catalog-to-variants" />

## Відповідальність

<ProcessDiagram process-id="product_management.catalog-to-variants" view="ownership" direction="LR" />

## Capabilities

<ProcessDiagram process-id="product_management.catalog-to-variants" view="capability" direction="LR" />

## Контроль і recovery

Модель описує один допустимий бізнес-сценарій, не новий виконавчий рушій. Tenant і capability contract перевіряються до кожної операції. Для action-bound кроків незалежні Plan та Action approvals і Platform Workflow обов'язкові. При невідомому результаті — native readback та контрольоване врегулювання, не сліпий повтор. Послідовність не є однією глобальною транзакцією. Source verification не підтверджує production acceptance. Shopify не потрібний.

## Карта коду

- `app/Domains/ProductManagement/Application/Service/ProductManagementService.php`
