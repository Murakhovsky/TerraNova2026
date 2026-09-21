---
title: Платформа глобального пошуку та команд
description: Канонічний Search & Commands runtime COS з module-owned entity search, command palette, tenant filtering та server-side ranking.
status: active
updated: 2026-09-21
kind: architecture
---

# Платформа глобального пошуку та команд

Wave 12.15 закриває глобальний пошук і command palette як спільну Experience Platform capability.

Канонічний потік:

```text
Workspace Shell
    ↓ Turbo Frame GET
/workspace/search?q=...
    ↓
GlobalSearchService
    ↓
WebExtensionContextCatalog
    ├─ CoreCommandCatalog
    ├─ module web.commands
    └─ module web.search
         ↓
   Application / Domain read contracts
         ↓
SearchResult + EntityRef
    ↓
rank + deduplicate
    ↓
server-rendered results
```

## Що вважається глобальним пошуком

Глобальний пошук не обмежується navigation destinations.

Канонічний result vocabulary:

- `command` — команда або navigation affordance;
- `workspace` — функціональна поверхня;
- `entity` — конкретна business entity з `EntityRef`.

Перші реальні entity projections:

- Sales deals;
- Sales leads;
- Property assets/listings.

## Володіння

Shared `GlobalSearchService` не імпортує Sales, Property або інший Domain.

Domain/module володіє:

- способом отримання своїх entity candidates;
- label/subtitle;
- canonical deep link;
- `EntityRef`.

Shared layer володіє:

- query normalization;
- aggregation;
- command composition;
- ranking;
- deduplication;
- limit;
- rendering contract.

## Пошук сутностей Sales

`SalesWebProvider` використовує `SalesWorkspaceReadModelInterface`.

Пошук deals і leads проходить через tenant-scoped read model:

```text
WebExtensionContext.organizationId
      ↓
SalesWorkspaceReadModelInterface
      ↓
SearchResult(kind=entity)
      ↓
EntityRef(sales.deal / sales.lead)
```

Web provider не містить SQL.

## Пошук сутностей Property

`PropertyWebProvider` використовує `PropertyReferencePort`.

`searchPropertyReferences()` знаходить candidates, після чого `getPropertyPresentation()` формує human-readable label та canonical public deep link, якщо listing має slug.

Результат використовує:

```text
EntityRef(property.asset, asset_id)
```

Unpublished asset лишається доступним через Property Workspace, а не через вигаданий public URL.

## Палітра команд

Command palette використовує той самий `CoreCommandCatalog` та module `web.commands`, що й Shell.

Порожній query повертає commands.

Непорожній query об'єднує:

- matching commands;
- workspace destinations;
- entity results.

Keyboard behavior належить Stimulus:

- `/` або `Ctrl/Cmd+K` — відкрити;
- Arrow Up / Down — selection;
- Enter — activate;
- Escape — close.

Browser не виконує ranking і не читає business data.

## Безпека

`/workspace/search` вимагає authenticated Symfony session.

Organization та role беруться із server-side `TenantContext`, а не з query params.

Module activation перевіряється Web Extension Runtime.

Entity provider отримує вже trusted `WebExtensionContext`.

## Межі

Заборонено:

- SQL у Web search provider;
- internal REST із Symfony Web у власний API;
- client-side business index як source of truth;
- передавати organization id із browser як authority;
- hardcode-ити список Domains у `GlobalSearchService`;
- дублювати module commands у локальному palette registry;
- повертати entity без canonical `EntityRef`, якщо result представляє business object.

## Масштабування

Для великих dataset конкретний Domain може замінити свій read adapter на search index або specialized projection.

Shared contract при цьому не змінюється:

```text
SearchProviderInterface
      ↓
list<SearchResult>
```

Search engine є Infrastructure detail конкретного module, а не новим shared Domain.

## Перевірка

CI перевіряє:

- module-owned `web.search` / `web.commands`;
- tenant-aware controller;
- відсутність internal REST;
- keyboard palette behavior;
- shared `CoreCommandCatalog`;
- Sales entity search через `SalesWorkspaceReadModelInterface`;
- Property entity search через `PropertyReferencePort`;
- `EntityRef` для business entity results;
- відсутність SQL у Web providers;
- runtime smoke `cos:web:search:smoke`.
