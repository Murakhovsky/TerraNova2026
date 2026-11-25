---
title: Product CSV → Native Catalog
description: Канонічна модель бізнес-процесу Product Management.
status: draft
updated: 2026-11-24
kind: workflow
contract: workflow-v2
process_state: as-is
process_id: product_management.csv-to-native-catalog
---

# Product CSV → Native Catalog

## Бізнес-мета

- Staged data is reviewed before mutation
- Approved import Action writes native catalog
- Native batch report shows actual results

## Учасники

- Product manager
- Approved Product Action worker
- Independent reviewer

## Тригер

A bounded CSV needs validated native catalog intake.

## Процес

<ProcessDiagram process-id="product_management.csv-to-native-catalog" />

## Відповідальність

<ProcessDiagram process-id="product_management.csv-to-native-catalog" view="ownership" direction="LR" />

## Capabilities

<ProcessDiagram process-id="product_management.csv-to-native-catalog" view="capability" direction="LR" />

## Контроль і recovery

Модель описує один допустимий бізнес-сценарій, не новий виконавчий рушій. Tenant і capability contract перевіряються до кожної операції. Для action-bound кроків незалежні Plan та Action approvals і Platform Workflow обов'язкові. При невідомому результаті — native readback та контрольоване врегулювання, не сліпий повтор. Послідовність не є однією глобальною транзакцією. Source verification не підтверджує production acceptance. Shopify не потрібний.

## Карта коду

- `app/Domains/ProductManagement/Application/Service/CsvProductImportService.php`
- `app/Domains/ProductManagement/Automation/Action/ProductImportApplyHandler.php`
