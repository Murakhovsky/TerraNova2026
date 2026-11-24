---
title: Product Stock → Reconciliation
description: Канонічна модель бізнес-процесу Product Management.
status: draft
updated: 2026-11-24
kind: workflow
contract: workflow-v2
process_state: as-is
process_id: product_management.stock-to-reconciliation
---

# Product Stock → Reconciliation

## Бізнес-мета

- Physical and reserved quantities reconcile
- Transfer is paired and reservations do not change physical stock
- Read-only audit reports discrepancies without repair

## Учасники

- Warehouse manager
- Independent reviewer

## Тригер

Documented goods are received at a local warehouse.

## Процес

<ProcessDiagram process-id="product_management.stock-to-reconciliation" />

## Відповідальність

<ProcessDiagram process-id="product_management.stock-to-reconciliation" view="ownership" direction="LR" />

## Capabilities

<ProcessDiagram process-id="product_management.stock-to-reconciliation" view="capability" direction="LR" />

## Контроль і recovery

Модель описує один допустимий бізнес-сценарій, не новий виконавчий рушій. Tenant і capability contract перевіряються до кожної операції. Для action-bound кроків незалежні Plan та Action approvals і Platform Workflow обов'язкові. При невідомому результаті — native readback та контрольоване врегулювання, не сліпий повтор. Послідовність не є однією глобальною транзакцією. Source verification не підтверджує production acceptance. Shopify не потрібний.

## Карта коду

- `app/Domains/ProductManagement/Application/Service/ProductManagementService.php`
- `app/Domains/ProductManagement/Infrastructure/Persistence/MySqlProductAnalytics.php`
