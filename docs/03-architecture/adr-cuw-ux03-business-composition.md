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

## Негативне приймання прав

Authenticated користувач із роллю buyer і валідним CSRF має отримати HTTP 403 як на читання менеджерського Business Workspace, так і на збереження персонального Workspace filter; native Property rows мають залишитися незмінними. Це окремий E2E сценарій, а не висновок зі статичного UI hiding.

## Мовне приймання бізнесових контекстів

Збережено всі server-side Business Workspace дані та UI translations у наявному Symfony Translator. `WorkspaceDefinition.label` перекладається як presentation-only lookup; доменні дані клієнта, адреси, ліди, документи не перекладаються. Playwright перевіряє UK/EN з наявним серверним locale switch і тим самим authenticated tenant.

## Пов’язані бізнес-сутності: перший реальний провайдер

UX-03 підключає tenant-scoped читання `Sales Client Case → Property Match → Property Asset` через Application `RelatedEntityReadInterface`, яке реалізоване в Infrastructure, без бізнесових мутацій або SQL у Web Controller. Client Case і Property незалежно перевіряють поточну організацію. На екрані показуються лише позитивно верифіковані property assets, dedup, межа 10, canonical EntityLinkResolver або наявний typed Property Get screen. Для інших Domain, які ще не реалізували relationship read model, відображається відсутність підтверджених зв’язків, не вигадані відносини. Unit тест перевіряє позитивний match, чужий tenant, відсутній Asset та дублікати.

## Контекстний Business Summary

Вибрану tenant-підтверджену сутність збагачено через Application `BusinessEntitySummaryReadInterface` і наявні Sales Workspace та Property Reference read contracts. UI використовує наявний `CosEntitySummary`, показуючи тільки реально присутні status, stage, owner, next action чи type/address. Немає вигаданих фінансових KPI, відсутні поля приховуються. При збої read model відображається невизначеність, а не вигаданий успіх. Unit/E2E тести включають чужий tenant, неправильний ID та реальний Property selected summary.
