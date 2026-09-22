---
title: "PHASE 15 — Quality Closure"
description: "Після Web Platform v1 freeze PHASE 15 закриває автоматизовану WCAG 2.2 AA перевірку production Web surfaces без зміни frozen platform contracts."
status: active
updated: 2026-09-22
kind: architecture
---

# PHASE 15 — Закриття якості

PHASE 9–14 завершили production adoption і legacy shell closure. ADR-0011 заморозив Web Platform v1, тому наступна хвиля не перебудовує foundation.

PHASE 15 закриває quality debt, який був свідомо відкладений у Wave 12.23: heuristic accessibility checks доповнюються реальним axe-core audit.

## Контракт доступності

CI запускає `@axe-core/playwright@4.13.0` на канонічних public surfaces:

- `/`;
- `/auth/login`;
- `/property/catalog`.

Перевірка обмежена WCAG 2.x / 2.1 / 2.2 Level A + AA tags:

```text
wcag2a
wcag2aa
wcag21a
wcag21aa
wcag22aa
```

Будь-яка axe violation робить gate червоним.

## Подвійний рівень

PHASE 15 не видаляє існуючий Wave 12.23 browser quality suite.

Він лишається швидким regression layer для:

- document language/title;
- duplicate ids;
- labels;
- alt;
- accessible names;
- keyboard focus;
- mobile horizontal overflow;
- browser/runtime errors;
- screenshot sanity.

Axe додає standards-based semantic accessibility audit поверх цих перевірок.

## Докази виконання

JSON-звіти зберігаються у:

```text
tmp/web-accessibility/
```

CI завантажує їх як artifact навіть при падінні gate.

## Межа заморожених контрактів

PHASE 15 не змінює:

- EntityRef;
- UIAction;
- Workspace contracts;
- DataGrid contracts;
- Forms contracts;
- Shell public models;
- realtime/native/deep-link contracts.

Тому ADR-0011 change-control не потребує нового ADR: зміна є additive quality capability.

## Критерії завершення

- `@axe-core/playwright` pinned до конкретної версії;
- public reference surfaces проходять WCAG 2.2 AA automated audit;
- existing heuristic/browser quality suite залишається;
- axe JSON evidence зберігається у CI;
- PHASE 15 має architecture gate;
- Web Platform v1 frozen contracts не змінені.
