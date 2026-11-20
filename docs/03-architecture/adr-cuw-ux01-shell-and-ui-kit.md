---
title: ADR — Канонічний Shell UX-01
description: Повторне використання Symfony UI.
status: isolated-pilot
---

# ADR — Канонічний робочий простір UX-01

Повторно використовуємо Symfony SSR, Twig, Turbo, Stimulus і дизайн-токени COS. Окремий маршрут /workspace/unified наслідує workspace_shell.html.twig та показує тільки зареєстровані tenant-enabled WorkspaceDefinition. Пошук через GlobalSearchService використовує справжні Application ReadModels, а не створює Goal або мутацію.

Tabler залишається кандидатом, але не runtime dependency: до встановлення необхідно окремо перевірити ліцензію конкретної версії, dependency/compatibility з Bootstrap, Turbo, accessibility і регресію. Новий frontend framework не вводимо.

Маршрут opt-in, legacy URL збережені; rollback = вилучення нового entry/route. Зареєстрований Workspace не означає execution-verified. Відсутність перевіреного AI/realtime handshake зафіксована як UX-01 gap.
