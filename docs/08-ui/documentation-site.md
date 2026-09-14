---
title: Documentation Site
description: Як /docs перетворюється на навігаційний documentation surface без дублювання source of truth.
status: active
updated: 2026-09-14
kind: ui
---

# Documentation Site

```text
main code + docs
      ↓
reference generation
      ↓
VitePress build
      ↓
public/docs
```

`public/docs` є disposable static artifact і не редагується вручну.

## UX

Documentation surface дає sidebar, local full-text search, page outline, previous/next navigation, deep links, frontmatter metadata, edit-on-GitHub і clickable System Map.

## Renderer

Stable VitePress `1.6.4` запускається окремо від основного Vite application build.

```text
npm run build       → public/build
npm run docs:build  → public/docs
```

## Branch model

Окремої documentation/code branch model більше немає. `main` містить code, tests, docs, generators і deployment metadata. Кожен docs build описує current checkout, а не сусідню branch.

## Generated reference

Modules, capabilities, routes, permissions, use cases, events і commands генеруються з current `main` перед build.

## Editing workflow

```text
change code / architecture in main
        ↓
update narrative docs when meaning changed
        ↓
generate reference
        ↓
Docs CI validates same commit
        ↓
VitePress build / deployment
```

## Code map

```text
docs/
  index.md
  03-architecture/system-map.md
  .vitepress/
    config.mjs
    sidebar.mjs
    check.mjs
    generate-*.php
    theme/
package.json
.github/workflows/docs.yml
public/docs/  generated
```
