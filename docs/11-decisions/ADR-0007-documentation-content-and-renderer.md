---
title: ADR-0007 — Documentation content and renderer are separated
status: accepted
updated: 2026-09-12
kind: decision
---

# Context

COS documentation має бути зручною як великий technical documentation site, але canonical knowledge уже живе поруч із кодом у `/docs`. Перенесення content у CMS, PHP views або окремий frontend project створило б другу source of truth.

Основний application frontend використовує Vite 8 і власний build у `public/build`. Stable VitePress 1.6.4 має власний dependency graph, тому змішувати обидва bundler runtime без необхідності недоцільно.

# Decision

**Canonical content залишається в `/docs`; documentation website є окремим static rendering build цього самого content.**

```text
/docs
  ↓
VitePress 1.6.4
  ↓
/public/docs
```

Main application Vite build не імпортує VitePress. Docs runner викликається окремими scripts і генерує static artifact.

Navigation будується з canonical numeric sections, а generated output не комітиться.

# Rationale

- одна content source;
- docs-as-code поруч із implementation;
- готовий UX technical documentation: sidebar, outline, search, deep links;
- static deployment без PHP Markdown renderer;
- docs tooling не змінює application bundle;
- CI може валідовувати build незалежно.

# Alternatives considered

## Custom Markdown renderer у PHP

Відхилено: потрібно самостійно підтримувати Markdown parsing, navigation, search, anchors, highlighting і security edge cases.

## Content copy у CMS/database

Відхилено: створює synchronization problem між repo documentation та published site.

## Додати VitePress як dependency основного frontend bundle

Відхилено на цьому етапі: application використовує Vite 8, тоді як stable VitePress 1.6.4 має власний Vite dependency line. Docs build не потребує змішування цих dependency graphs.

# Consequences

Позитивні:

- `/docs` залишається канонічним;
- generated site disposable/rebuildable;
- documentation UX може еволюціонувати незалежно від COS UI;
- build failure легко перевіряти в CI.

Обмеження:

- build runner потребує Node/npm access;
- publication `public/docs` має входити в deployment pipeline;
- authentication приватних sections потребуватиме окремого рішення.

# Compatibility / Migration

Існуючі `.md` не переносяться. Numeric canonical directories автоматично формують sidebar. Legacy/detail directories залишаються repository reference і не входять у головний navigation/search.

# Verification

- `npm run docs:build` має успішно генерувати `public/docs`;
- workflow `.github/workflows/docs.yml` запускає build для docs changes;
- generated output і VitePress cache ігноруються Git;
- application `npm run build` лишається окремим Vite flow.

# Related

- `docs/08-ui/documentation-site.md`
- `docs/10-operations/documentation-build.md`
- `docs/09-development/documentation-rules.md`
- `package.json`
