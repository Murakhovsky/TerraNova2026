---
title: Wave 13.1 — Нормалізація Application Shell
description: Application Shell споживає канонічні layout tokens і breakpoint contracts замість локального володіння глобальною геометрією сторінок.
status: active
updated: 2026-09-23
kind: architecture
---

# Wave 13.1 — Нормалізація Application Shell

## Мета

Shell рендериться один раз для Workspace/System surfaces. Production pages не володіють global navigation geometry, page width або mobile shell behavior.

Wave 12 уже надав functional Shell contract. Wave 13.1 нормалізує його visual ownership.

## Канонічна власність

`tokens.css` володіє sidebar width, page maximum width, responsive page padding, top bar heights, mobile navigation height, mobile drawer width і z-index roles.

`shell.css` лише споживає ці tokens.

## Адаптивні режими

Shell використовує canonical breakpoint contract:

```text
tablet  <= 1050px
mobile  <= 650px
```

Compact-mobile залишається component/page composition concern на `390px`.

CSS custom properties не можна використовувати в умовах media query, тому числові значення media query дзеркалять frozen breakpoint tokens і контролюються architecture tests.

## Відповідальність Page

Page всередині Shell може обирати Page Archetype і Patterns, але не може перевизначати sidebar width, top bar geometry, global content max width, global page padding, bottom navigation height або global navigation layout.

## Результат

Shell став споживачем COS Visual System, а не паралельним власником layout constants.
