---
title: Управління товарами та запасами як єдиний бізнес-домен
description: Архітектурне рішення щодо єдиного домену управління товарами, каталогом, запасами й інтеграціями.
status: proposed
updated: 2026-11-18
kind: decision
---

# ADR-0016: Єдиний домен управління товарами та запасами

## Контекст

COS потребує універсального управління SKU, каталогом, локаціями, запасами,
рухами та базовими цінами. Commerce є майбутнім Business Package, не новим
виконавчим Domain. Перший пілот працює із Shopify.

## Рішення

1. Ввести один Domain `product_management` з внутрішніми компонентами
   Catalog, Inventory, Movements, Pricing та Integration.
2. Домен володіє всіма записами `pm_*`. Він не пише в Sales, Finance,
   Procurement або Property. Публікує бізнесові факти через наявний Outbox.
3. Shopify підключається read-only. Зовнішні дані зберігаються як
   provenance/mappings і не мають права перезаписувати COS-authoritative
   стан, доки явно не затверджено політику володіння.
4. `Product Management` встановлюється через штатний module lifecycle і
   вимкнений за замовчуванням. Ніякого другого executor, queue чи event bus.
5. UI будується з майбутніх Unified Workspace contributions. E0–E4 не змінюють
   універсальний Workspace.
6. Domain product/variant identity не повинен залежати від Shopify GID.

## Контракти

- Commands/queries описуються через `CapabilityContract` і JSON Schema.
- Business writes виконують Domain Use Cases; автоматизовані зміни тільки
  через канонічні Kernel Policy/Approval/Action та Platform Workflow.
- Stock movements є append-only. Balance є перевірюваною проєкцією.
- Webhook HMAC перевіряється за оригінальними raw bytes; повтор не є новою подією.

## Обмеження

Release E0–E4 не дозволяє зовнішні Shopify writes, automated stock writes
від AI/CRON, bulk-публікацію, order/checkout та незалежний workflow runtime.
Перший пілот: один Shopify connection, готовність до multi-tenant/multi-store.

## Приймання

Домен виявляється ModuleDiscovery і підкоряється tenant activation;
виконувані capabilities мають живі adapters і тести;
усі записи та зовнішні mapping tenant-scoped;
синхронізація не дублює товари й залишки, а невизначені конфлікти карантинить.
