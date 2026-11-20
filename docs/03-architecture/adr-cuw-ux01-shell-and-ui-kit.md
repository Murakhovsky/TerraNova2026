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

## Мовний контракт

Залишаємо один канонічний `cos_web_locale_switch` і Symfony Translator. Нові мітки UX-01 мають ключі `uw.*` із каталогами UK/EN, назви Domain Workspace перекладаються лише для UI через `|trans`. Відсутні нові клієнтські словники. Playwright перевіряє перемикання English → Українська зі збереженням поточного маршруту і справжнього tenant context.

## Tabler spike, ліцензія та сумісність (20.11.2026)

Перевірено офіційні [Tabler README](https://github.com/tabler/tabler), [releases](https://github.com/tabler/tabler/releases) і [документацію](https://docs.tabler.io/ui/getting-started).

- Ядро Tabler доступне за **MIT**, але bundled `dist/libs` мають **власні ліцензії**. ApexCharts v5+ має окрему dual/commercial модель; повний пакет із графіками не вважати автоматично MIT-only.
- У Tabler 1.6.x Bootstrap 5.3.8 вбудований у Tabler source/JS, а не є незалежним npm peer dependency. Додавання повного JS bundle до наявних COS Bootstrap/Turbo/Stimulus ризикує подвійним init, конфліктами style variables і lifecycle hooks.
- Release notes фіксують **вилучення Turbo integration**. Це окрема інтеграційна несумісність із server-first UX, а не причина відмовлятися від CSS-патернів.
- Браузерна мінімальна підтримка Tabler 1.6.x, заявлена upstream: Chrome 123, Firefox 128, Safari 17.5. Це не заміна реального CI проти цільових браузерів COS.

**Рішення UX-01:** не підключати `@tabler/core` runtime, CDN, ApexCharts/third-party bundles або другу Bootstrap копію. Наявний COS Design System / Twig Components / Tokens є канонічним. Допустимий подальший вибір лише ізольованих візуальних primitives після license review, CSP/security audit та Playwright parity. Таким чином Tabler spike завершено рішенням **ADAPT DESIGN, NOT RUNTIME** без new dependency, що зберігає rollback та Symfony UX контракти.
