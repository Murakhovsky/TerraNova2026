---
title: Внутрішній каталог UI-компонентів
description: Канонічний component playground COS для інвентарю, станів, варіантів і reference surfaces Web Experience Platform.
status: active
updated: 2026-09-21
kind: architecture
---

# Внутрішній каталог UI-компонентів

Wave 12.22 не створює другу design-system сторінку. Канонічний `/dev/ui` розширюється до **internal component playground**.

Каталог залишається:

- manager-only через `ROLE_MANAGER`;
- `noindex, nofollow`;
- `Cache-Control: no-store, private`;
- частиною Symfony Web Experience Platform;
- без Domain та data-access залежностей у registry.

## Реєстр компонентів

`UiCatalogRegistry` є явним інвентарем усіх канонічних `Cos*` Twig Components.

Кожен запис містить:

- component name;
- category;
- коротке призначення;
- reference states;
- reference surface;
- maturity.

CI порівнює registry з файлами `symfony/src/Web/Experience/Component/Cos*.php`. Новий компонент без запису в каталозі вважається незавершеним platform change.

## Інтерактивний майданчик

`/dev/ui` підтримує:

- пошук за component name, category, description і state;
- фільтр за category;
- кількість видимих компонентів;
- групування inventory;
- посилання на canonical reference surface;
- живі specimens для foundation, feedback, forms та вже наявних interaction/data components;
- theme/style/density перемикачі існуючого Style Lab.

Фільтрація є browser-only поведінкою через Stimulus. Вона не створює API та не переносить UI ownership на клієнт.

## Межі

Каталог не є:

- публічною документацією;
- Domain-specific showcase;
- альтернативним Storybook/SPA;
- місцем для business logic;
- джерелом production configuration.

Його задача: показати, що platform component існує, які стани він підтримує і де перевірити його поведінку.
