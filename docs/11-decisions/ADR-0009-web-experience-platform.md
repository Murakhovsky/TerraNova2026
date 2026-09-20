---
title: ADR-0009 — Symfony Web & Experience Platform є канонічним UI runtime COS
description: Рішення побудувати server-first Experience Platform як Application adapter із module-owned UI extensions, unified actions та native-ready surfaces.
status: accepted
updated: 2026-09-21
kind: decision
---

# ADR-0009 — Symfony Web & Experience Platform є канонічним UI runtime COS

## Контекст

Після завершення Symfony-only cutover COS більше не потребує compatibility architecture між Phalcon і Symfony. Водночас presentation layer усе ще містить історичний Vite/PHTML runtime, а нові Domains ризикують створювати власні UI підходи, navigation hardcode, локальні action models та окремі JavaScript stacks.

Wave 12 вводить одну горизонтальну Experience Platform для Desktop Web, Mobile Web, PWA, майбутнього Native shell, Agent UI та складних JS Islands.

## Рішення

**Symfony Web є канонічним Application adapter COS, а новий Domain інтегрується у Web через platform contracts та module-owned extension contributions, не створюючи власний frontend stack.**

Цільовий runtime:

```text
Symfony SSR
  ↓
Twig Components
  ↓
Turbo + Live Components
  ↓
Stimulus
  ↓
Mercure / Turbo Streams
  ↓
PWA / Native Bridge / JS Islands
```

Bootstrap є responsive/layout foundation, але не визначає visual identity COS.

## Обов'язкові межі

1. Web, API, Console та Agents використовують спільні Commands, Queries, Policies, Workflows і Application Services.
2. Symfony Web не викликає власний REST API для server-side business execution.
3. Web Controller не звертається напряму до Doctrine Repository.
4. Twig/Components/Stimulus/LiveComponent не містять business rules.
5. Domain не залежить від Web/UI.
6. Primitive UI не знає про Domains.
7. Нові UI extension points реєструються module-owned contributions через canonical Module Extension Runtime.
8. Unified `UIAction` є presentation model для human/agent/workflow affordances, але виконання завжди сходиться до Application Command.
9. `EntityRef` є канонічним UI reference на business entity та основою для links/search/notifications/deep links.
10. Новий frontend framework для standard business UI потребує окремого ADR.

## Канонічні Web extension points

Wave 12 резервує platform-level точки:

- `web.navigation`
- `web.search`
- `web.commands`
- `web.workspace`
- `web.workspace.extensions`
- `web.dashboard_widgets`
- `web.entity_links`
- `web.notifications`
- `web.activity`
- `web.actions`

Їх збирає існуючий `ModuleExtensionRegistry`; Shell не hardcode-ить Domain integration.

## UI decision matrix

```text
Static/read UI                → Twig SSR
Reusable server UI            → Twig Component
Independent navigation block  → Turbo Frame
Reactive server state         → LiveComponent
Browser/DOM behavior           → Stimulus
Server → browser update       → Turbo Stream + Mercure
Complex client runtime         → JS Island
Device capability              → Native Bridge
```

Відхилення потребує ADR.

## Перехідний стан

Поточні Vite/PHTML surfaces не видаляються цим ADR. Вони є transitional implementation і мігрують вертикальними slices після появи Twig/UX runtime.

Заборонено робити big-bang rewrite або ламати production surfaces лише заради технологічної чистоти.

## Наслідки

- Web Platform стає горизонтальною capability нарівні з Domain/Application/Workflow/Agent runtimes.
- Новий Domain має надавати Queries, Commands, Workflows, Permissions і UI Providers, а не власний frontend application.
- Navigation, Search, Workspaces, Actions та AI UI можуть масштабуватися без dependency від shared Shell до конкретних Domains.
- Vite може лишатися для складних Islands або перехідних entrypoints, але не є архітектурною вимогою стандартного COS UI.
- Wave 12 foundation перевіряється executable architecture gates у CI.
