---
title: ADR — Канонічний Shell UX-01
description: Повторне використання Symfony UI.
status: isolated-pilot
updated: 2026-11-20
kind: architecture
---

# ADR — Канонічний робочий простір UX-01

Повторно використовуємо Symfony SSR, Twig, Turbo, Stimulus і дизайн-токени COS. Окремий маршрут /workspace/unified наслідує workspace_shell.html.twig та показує тільки зареєстровані tenant-enabled WorkspaceDefinition. Пошук через GlobalSearchService використовує справжні Application ReadModels, а не створює Goal або мутацію.

Tabler залишається кандидатом, але не runtime dependency: до встановлення необхідно окремо перевірити ліцензію конкретної версії, dependency/compatibility з Bootstrap, Turbo, accessibility і регресію. Новий frontend framework не вводимо.

Маршрут opt-in, legacy URL збережені; rollback = вилучення нового entry/route. Зареєстрований Workspace не означає execution-verified. Відсутність перевіреного AI/realtime handshake зафіксована як UX-01 gap.

## Перевірка мобільного оформлення

Сторінка UX-01 використовує вже встановлений Appearance Stimulus controller для світлої/темної теми та comfortable/compact щільності. Реальний Playwright сценарій перевіряє viewport 390×844, відсутність горизонтального переповнення, перемикання обох параметрів та повернення у світлу тему. Скриншот зберігається в ізольованому CI; результат на новому SHA повинен бути перевірений окремо від попереднього PASS.

## Доступність Command Palette

Стандартний `Ctrl/Cmd+K` відкриває ту саму командну палітру; фокус переходить у поле пошуку. `Tab` зберігає фокус усередині активного діалогу, `Escape` закриває його й повертає фокус до джерела виклику. Елемент має зв’язок `aria-controls`; окремий Playwright тест перевіряє keyboard open/focus/close.
