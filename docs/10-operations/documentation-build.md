---
title: Documentation Build
status: active
updated: 2026-09-14
kind: operations
---

# Documentation Build

COS documentation збирається з того самого `main` commit, що містить executable code.

## Canonical flow

```text
main code / manifests / contracts
        ↓
PHP reference generators
        ↓
docs/12-reference/*.md
        ↓
validation
        ↓
VitePress 1.6.4
        ↓
public/docs
```

Cross-branch checkout, `.docs-source` і `COS_DOCS_SOURCE_ROOT` більше не є частиною canonical pipeline.

## Local commands

```bash
npm run docs:generate
npm run docs:generate:check
npm run docs:check
npm run docs:dev
npm run docs:build
npm run docs:preview
```

`docs:dev` і `docs:build` перед запуском VitePress автоматично регенерують executable reference.

## Generated reference

Canonical generated pages:

```text
application-use-cases.md
commands.md
event-types.md
extension-points.md
module-capabilities.md
module-routes.md
permissions-capabilities.md
```

Authority для них — current code/manifests, а не ручний Markdown.

## CI

`.github/workflows/docs.yml`:

1. checkout поточного `main` commit;
2. setup Node 22 + PHP 8.2;
3. `npm run docs:generate`;
4. `npm run docs:generate:check`;
5. `npm run docs:check`;
6. `npm run docs:build`;
7. upload `public/docs` artifact;
8. push у `main` може deploy-ити `/docs` на AWS dev.

Workflow також запускається при relevant changes у `app/Domains/**`, Kernel module contracts і Web routing, бо ці files впливають на generated reference.

## Failure semantics

Build не готовий, якщо не проходить generator, generated check, internal links/frontmatter, Kernel/module version contracts або VitePress build.

GitHub Environment `AWS TN2026` окремо має дозволяти deployment із `main`; це repository-external protection rule.
