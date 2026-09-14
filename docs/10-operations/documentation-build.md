---
title: Documentation Build
status: active
updated: 2026-09-12
kind: operations
---

# Documentation Build

COS documentation website збирається статично з canonical `/docs`.

## Commands

```bash
npm run docs:dev
npm run docs:build
npm run docs:preview
```

Runner pinned до VitePress `1.6.4` через npm script, тому main application dependency graph і `package-lock.json` не змінюються.

## Build output

```text
docs/**
  ↓
VitePress
  ↓
public/docs/**
```

`public/docs` є generated artifact і не комітиться.

## Deployment

Після `npm run docs:build` web server може віддавати `/docs/` як static directory. Поточні Apache rules пропускають існуючі files/directories до Phalcon front controller, тому docs pages/assets не потребують application controller.

Clean URLs вимкнені навмисно: generated `.html` path є реальним файлом і проходить current rewrite rules без fallback у PHP router.

## CI

Workflow `.github/workflows/docs.yml`:

1. checkout;
2. Node 22;
3. `npm run docs:build`;
4. upload `public/docs` як build artifact.

CI не деплоїть documentation автоматично. Він перевіряє, що static site збирається. Publication/deployment може бути доданий окремо після визначення production target.

## Failure semantics

Docs build failure блокує documentation change до виправлення. Generated artifact від попереднього successful build не є доказом, що current source валідний.
