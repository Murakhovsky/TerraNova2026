---
title: Documentation Sync Audit
description: Поточний рівень відповідності COS documentation executable code і межі автоматичної перевірки.
status: active
updated: 2026-09-15
kind: reference
scope: as-is
---

# Documentation Sync Audit

Ця сторінка фіксує не те, що документація **обіцяє**, а те, наскільки її canonical WEB layer прив’язаний до executable state у `main`.

## Поточний висновок

Стан документації є **високо синхронізованим на рівні executable facts**, але ще не повним на рівні пояснення всіх реалізованих можливостей.

На поточному baseline:

- Kernel version звіряється з `app/Kernel/Module/KernelVersion.php`;
- усі installable Domains з `app/Domains/*/module.php` мають canonical overview;
- Domain versions у current scope та overview перевіряються проти manifests;
- modules/capabilities, permissions, events, application/routes і command DTO reference генеруються з коду;
- internal Markdown links і canonical frontmatter перевіряються;
- workflow pages перевіряються на обов’язкові `Business goal`, `Actors`, `Code map` sections;
- WEB home baseline тепер читає Kernel і Domain manifests безпосередньо з current checkout під час VitePress build.

## Coverage

| Area | State | Source of truth |
| --- | --- | --- |
| Kernel version | synchronized | `KernelVersion.php` |
| Installable Domain versions | synchronized | `app/Domains/*/module.php` |
| Installable Domain overview presence | 3 / 3 | manifests + `04-domains/*/overview.md` |
| Modules / capabilities / extensions | generated | runtime manifests / Kernel registry |
| Permissions | generated | executable permission sources |
| Event types | generated | executable event catalogues |
| Application / routes | generated | route/application contributors |
| Command DTOs | generated | executable command classes |
| Workflow document structure | checked | docs integrity checker |
| Narrative semantics | curated | architecture/domain documentation |

## Installable vs supporting areas

Installable runtime Domains are discovered from `module.php`. Supporting areas without module manifests are not presented as fully installable Domains.

Current supporting areas are documented separately:

- Content;
- Identity;
- Spatial.

See [Supporting Domains and Extracted Areas](../04-domains/supporting-domains.md).

## Що ще не є повним

### Property depth

Property `0.11.0` already contains a much wider executable surface than one overview page can explain: canonical asset registry, structure, identity/provenance, Inventory, Listing/Publication, history, analytics, intelligence and external network boundary.

The overview is correct, but documentation depth is still below code depth. This is a **coverage gap**, not currently detected factual drift.

### Curated narrative cannot be fully generated

A generator can prove that a route, event or capability exists. It cannot prove that a paragraph correctly explains why a bounded context owns a business concept. Architectural narrative still needs review against code and accepted ADRs.

### Deep technical documents remain outside the numbered canonical tree

The repository still contains historical/deep technical material under paths such as `docs/architecture`, `docs/api`, `docs/sales`, `docs/diagnostic` and standalone technical pages. They remain useful source material, but the numbered `00–12` tree is the canonical navigation layer for the WEB documentation.

This separation should stay explicit so search results do not quietly turn old implementation notes into product truth.

## WEB synchronization contract

The homepage must not manually maintain runtime versions.

```text
KernelVersion.php ─────┐
                       ├─> VitePress build ─> System Status UI
Domain module.php ─────┘

Executable registries ─> generated reference
Curated docs ──────────> concepts / workflows / architecture rationale
```

This keeps two different responsibilities separate:

1. **Facts that code can prove** are derived from code.
2. **Meaning that humans must explain** remains curated documentation.

## Verification commands

```bash
npm run docs:generate:check
npm run docs:check
npm run docs:build
```

See also [Documentation Site](../08-ui/documentation-site.md) and [Generated Reference](../12-reference/README.md).
