---
title: Product Physical Count → Correction
description: Канонічна модель бізнес-процесу Product Management.
status: draft
updated: 2026-11-24
kind: workflow
contract: workflow-v2
process_state: as-is
process_id: product_management.physical-count-to-correction
---

# Product Physical Count → Correction

## Бізнес-мета

- Revision-pinned physical count is independently approved
- Correction and count receipt are atomic
- Native ledger and count outcome are verified

## Учасники

- Warehouse manager
- Approved Product Action worker
- Independent reviewer

## Тригер

A physical count document identifies discrepancies for tracked SKU.

## Процес

<ProcessDiagram process-id="product_management.physical-count-to-correction" />

## Відповідальність

<ProcessDiagram process-id="product_management.physical-count-to-correction" view="ownership" direction="LR" />

## Capabilities

<ProcessDiagram process-id="product_management.physical-count-to-correction" view="capability" direction="LR" />

## Контроль і recovery

Модель описує один допустимий бізнес-сценарій, не новий виконавчий рушій. Tenant і capability contract перевіряються до кожної операції. Для action-bound кроків незалежні Plan та Action approvals і Platform Workflow обов'язкові. При невідомому результаті — native readback та контрольоване врегулювання, не сліпий повтор. Послідовність не є однією глобальною транзакцією. Source verification не підтверджує production acceptance. Shopify не потрібний.

## Карта коду

- `app/Domains/ProductManagement/Application/Service/ProductManagementService.php`
- `app/Domains/ProductManagement/Infrastructure/Persistence/MySqlProductAnalytics.php`
