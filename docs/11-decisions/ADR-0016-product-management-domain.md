---
title: Управління товарами та запасами як єдиний бізнес-домен
description: Архітектурне рішення щодо єдиного домену управління товарами, каталогом, запасами й інтеграціями.
status: proposed
updated: 2026-11-19
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

## Уточнення рішення: імпорт CSV та Product Media

7. CSV проходить `preview` і створює незмінний digest вихідного файлу
   та зафіксованих рядків. Застосування виконує тільки канонічний
   `product_management.import.apply` Action із незалежним Approval,
   Policy та `wf:` Workflow idempotency. Зміни стану товарів надходять
   через штатний Product Use Case; повторні запити використовують його
   незмінні receipt. Паралельні воркери на одному пакеті блокуються
   MySQL advisory lock. Пошкоджені результати не виправляються автоматично.
8. Product Media повторно використовує `Platform FileStorage` і
   tenant-verified `cos_document_files` через окремий read-only port.
   Домен володіє лише `pm_media_refs`, а не байтами файлу чи системою
   зовнішнього зберігання. Для attachment потрібні коректні SHA-256,
   розмір, MIME, вміст зображення та tenant-bound storage key.
9. Один активний cover дозволений у межах одного Product або Variant.
   Від'єднання media не видаляє спільний Platform-файл. Product reads не
   повертають внутрішні storage keys.

### Відкриті критерії приймання

- Наскрізний Goal → Plan → незалежні Approval → Workflow → Kernel Action →
  Product native receipt → Goal outcome перевірено в isolated MySQL E2E;
  production operator sign-off залишається обов'язковим.
- UI для завантаження та редагування Product Media через Unified Workspace.
- Реальний read-only Shopify smoke та операторський release sign-off.
