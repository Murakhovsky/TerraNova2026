---
title: Контракт типографіки COS
description: Канонічна типографіка COS, лабораторія кандидатів шрифтів і правила відображення числових та фінансових даних.
status: active
updated: 2026-09-21
kind: architecture
---

# Контракт типографіки COS — PHASE 3

## Призначення

Типографіка є частиною операційної мови COS, а не декоративним оформленням сторінки.

PHASE 3 фіксує semantic type contract до канонічного візуального проходу компонентів:

- щільний UI має залишатися читабельним у діапазоні 12–16 px;
- український і латинський текст використовують одну ієрархію;
- фінансові та data surfaces використовують вирівняні цифри;
- шкала типографіки є семантичною та спільною для всіх Domains;
- експерименти зі шрифтами залишаються оборотними й не змінюють frontend architecture.

## Лабораторія кандидатів

`/dev/ui` порівнює однаковий контент у трьох candidate stacks:

1. Geist;
2. Inter;
3. IBM Plex Sans.

Порівняння завжди містить:

- `123,450 €`;
- `Company Operating System`;
- `Потенційний клієнт`;
- `Продаж житлового комплексу`;
- `Pipeline Forecast`;
- звичайний operational body copy.

Лабораторія не завантажує сторонні шрифти приховано. Якщо candidate family не встановлена або не bundled, використовується оголошений fallback stack. Brand font приймається лише окремим рішенням щодо asset і ліцензування.

## Production baseline

Поки рішення щодо brand font не заморожене, production baseline залишається Inter із системними fallback.

Це не дозволяє візуальному експерименту випадково змінити всі production surfaces.

Candidate stacks виражаються лише через semantic variables:

```text
--cos-font-candidate-geist
--cos-font-candidate-inter
--cos-font-candidate-plex
```

Жоден Domain не може hardcode власний font family.

## Семантичні ролі типографіки

Канонічні ролі:

```text
Display
Title
Heading
Body
Meta
Label
Numeric / Money
```

Вони мапляться на чинну COS type scale та спільні line-height/weight tokens.

## Фінансова та числова типографіка

Вирівняні значення використовують:

```css
font-variant-numeric: tabular-nums lining-nums;
```

Канонічні semantic hooks:

```text
.cos-numeric
.cos-money
.cos-metric__value
.cos-data-grid__numeric
```

Це правило застосовується до money, KPI, delta, forecast, variance та інших даних, що порівнюються вертикально.

## Правило для Domains

Domains надають content і business semantics.

Domains не визначають:

- font families;
- незалежні type scales;
- випадкові розміри headings;
- локальне оформлення financial numbers.

## Критерії завершення PHASE 3

PHASE 3 завершена, коли:

- три кандидати видно поруч у `/dev/ui`;
- присутні точні reference strings;
- існує канонічна semantic type scale;
- tabular numeric behavior реалізована у CSS, а не лише описана в документації;
- production залишається стабільним під час оцінювання кандидатів;
- CI не дозволяє видалити або обійти typography contract.

Фінальний вибір brand font можна зробити після візуального огляду без зміни Web foundation.
