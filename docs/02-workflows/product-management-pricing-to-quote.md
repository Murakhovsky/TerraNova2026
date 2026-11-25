---
title: Product Pricing → Verified Quote
description: Канонічна модель бізнес-процесу Product Management.
status: draft
updated: 2026-11-24
kind: workflow
contract: workflow-v2
process_state: as-is
process_id: product_management.pricing-to-quote
---

# Product Pricing → Verified Quote

## Бізнес-мета

- Base and explicit list prices are revisioned
- Quantity tier is resolved in explicit currency without FX
- Price history remains readable

## Учасники

- Product manager

## Тригер

A known SKU needs a currency-specific wholesale offer.

## Процес

<ProcessDiagram process-id="product_management.pricing-to-quote" />

## Відповідальність

<ProcessDiagram process-id="product_management.pricing-to-quote" view="ownership" direction="LR" />

## Capabilities

<ProcessDiagram process-id="product_management.pricing-to-quote" view="capability" direction="LR" />

## Контроль і recovery

Модель описує один допустимий бізнес-сценарій, не новий виконавчий рушій. Tenant і capability contract перевіряються до кожної операції. Для action-bound кроків незалежні Plan та Action approvals і Platform Workflow обов'язкові. При невідомому результаті — native readback та контрольоване врегулювання, не сліпий повтор. Послідовність не є однією глобальною транзакцією. Source verification не підтверджує production acceptance. Shopify не потрібний.

## Карта коду

- `app/Domains/ProductManagement/Application/Service/PricingService.php`
