---
title: ADR — Бізнесові робочі простори UX-03
description: Складення Sales і Property без дублювання Domain стану.
status: isolated-pilot
updated: 2026-11-20
kind: architecture
---

# UX-03: бізнесові робочі простори

Універсальний маршрут верифікує Workspace ID через WorkspaceCompositionResolver і лише ті Domain Web Providers, які активні для поточного tenant. Пошук використовує WebExtensionCatalog, а обрана користувачем сутність відображається лише після позитивного tenant-scoped EntityRef readback серед результатів того самого запиту. Немає ручного SQL, власних доменних копій даних, другого frontend чи командного виконавця.

Sales (lead/deal/client) і Property використовують ті самі registered WorkspaceDefinition та Application Search adapters. Картки, список, URL-запити і сортування працюють через один серверний read path. Для незнайдених, відключених і чужих сутностей — fail-closed. EntityLinkResolver має перевагу над source SearchResult path. Активність — організаційна, не привласнюється вибраному запису. Непідтверджені AI/Process результати не видаються за факт.

Повні прямі мутації, пагінація джерела понад bounded search, справжні Saved Views, міждоменні зв’язки, AI делегування та production rollout мають окремі gates після інтеграції відповідних Domain контрактів. Після UX-03 нові UX-04+ не розпочинаємо без рішення.

## Надійність і мобільні перевірки

Результати провайдера мають fail-closed стан помилки читання, а не пустий успішний список. Посилання на сутність проходять перевірку лише root-relative шляху, відхиляються protocol-relative URL і керуючі символи. UX-03 містить окремі Playwright 390×844 smoke-сценарії для Property і Sales, скриншоти та перевірку відсутності write; проходження тестів на конкретному SHA потрібне до статусу приймання.

## Збережені вигляди

UX-03 реалізує обмежені до десяти персональні named filter presets для активного Workspace в серверній Symfony session, з перевіркою CSRF, tenant/user scope та повторюваним ключем. Збережений вигляд не є бізнесовою подією і не проходить через Command Bus, оскільки змінює лише UI preference, без Domain-side write. Немає cross-device синхронізації та гарантії збереження після очищення сесії. Для довгострокових Saved Views потрібний окремий контрольований preference contract.
