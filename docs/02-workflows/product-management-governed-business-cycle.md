---
title: Product Governed Business Cycle
description: Канонічна модель бізнес-процесу Product Management.
status: draft
updated: 2026-11-24
kind: workflow
contract: workflow-v2
process_state: to-be
process_id: product_management.governed-business-cycle
---

# Product Governed Business Cycle

## Бізнес-мета

- Eight approved native operations produce independently verified effects
- No unapproved Action or second executor
- Ledger and current native results are verified

## Учасники

- Approved Product Action worker

## Тригер

Signed AI or CRON intent is reviewed and adopted into an approved Plan.

## Процес

<ProcessDiagram process-id="product_management.governed-business-cycle" />

## Відповідальність

<ProcessDiagram process-id="product_management.governed-business-cycle" view="ownership" direction="LR" />

## Capabilities

<ProcessDiagram process-id="product_management.governed-business-cycle" view="capability" direction="LR" />

## Контроль і recovery

Модель описує один допустимий бізнес-сценарій, не новий виконавчий рушій. Tenant і capability contract перевіряються до кожної операції. Для action-bound кроків незалежні Plan та Action approvals і Platform Workflow обов'язкові. При невідомому результаті — native readback та контрольоване врегулювання, не сліпий повтор. Послідовність не є однією глобальною транзакцією. Source verification не підтверджує production acceptance. Shopify не потрібний.

## Карта коду

- `app/Domains/ProductManagement/Automation/Action/ProductGovernedOperationHandler.php`
