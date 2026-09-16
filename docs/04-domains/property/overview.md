---
title: Огляд домену Property
description: Канонічне середовище виконання активів нерухомості, Inventory, Listing/Publication, історії, аналітичного інтелекту та зовнішньої взаємодії.
status: active
updated: 2026-09-16
kind: domain
---

# Огляд домену Property

Property є канонічним власником **фізичного стану активу нерухомості** в COS та пов’язаних із ним комерційних представлень.

## Основний поділ

```text
Property Asset = що фізично існує
Inventory Item = як організація комерційно працює з активом
Listing        = як пропозиція представлена ринку
Publication    = де Listing опублікований
Sales          = попит, воронка та процес угоди
CRM            = люди й контекст відносин
```

Квартира не стає фізично `SOLD`. `SOLD` є комерційним станом Inventory. Сам актив продовжує існувати, навіть якщо конкретна організація більше його не продає.

Це розділення є одним із ключових інваріантів Property.

## Як читати домен

<div class="cos-system-map">
  <div class="cos-map-layer">
    <div class="cos-map-title">Маршрут знань Property</div>
    <div class="cos-map-grid">
      <a class="cos-map-node" href="./domain-model.html"><strong>Модель домену</strong><span>Asset, Inventory, Listing, Publication, структура та володіння.</span></a>
      <a class="cos-map-node" href="./lifecycle-and-runtime.html"><strong>Життєвий цикл і виконання</strong><span>Надходження об’єкта, ідентичність, комерційний цикл і перехід V0.12.</span></a>
      <a class="cos-map-node" href="./contracts-and-code-map.html"><strong>Контракти й код</strong><span>Порти довідника, мережева межа, сумісність і карта коду.</span></a>
    </div>
  </div>
</div>

## Поточний обсяг

Property `0.12.0` охоплює:

- канонічний реєстр активів і їхню структуру;
- ідентичність та походження даних;
- Inventory як комерційний облік;
- Listing і Publication;
- незмінювану історію подій;
- аналітику та інтелект із прив’язкою до доказів;
- зовнішню мережу Property Network і межу RESO;
- канонічний шлях запису стану через середовище виконання Property.

## Декларація середовища виконання

```text
id: property
version: 0.12.0
schema: 0.12.0
kernel: >=0.11.0 <0.12.0
enabled_by_default: true
```

## Канонічне правило зміни стану

```text
Web / API / Spatial
        ↓
PropertyCanonicalRuntimeService
        ↓
PropertyAsset / InventoryItem / Listing / Publication
        ↓
Domain Events → Kernel EventBus / Outbox
        ↓
Compatibility Projection
        ↓
tn_properties
```

`tn_properties` більше не є канонічним джерелом стану Property. Вона зберігається як перехідна проєкція сумісності там, де старі поверхні читання ще залежать від попередньої моделі.

## Межа з Sales і CRM

Property відповідає на питання **«що це за актив і як він представлений комерційно»**.

Sales відповідає на питання **«хто має попит, як проходить справа або угода і що потрібно зробити далі»**.

CRM зберігає та організовує контекст людей і відносин. Такий поділ не дає квартирі раптом стати клієнтом, а клієнту випадково успадкувати поле `area_m2`. Людство вже бачило достатньо універсальних таблиць.

## Пов’язаний процес і довідник

- [Надходження Property → публікація](../../02-workflows/property-submission-to-publication.md)
- [Модулі та можливості](../../12-reference/module-capabilities.md)
- [Події](../../12-reference/event-types.md)
- [Команди](../../12-reference/commands.md)
- [Маршрути](../../12-reference/module-routes.md)
