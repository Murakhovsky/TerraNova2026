---
title: COS Documentation
description: Канонічна документація Company Operating System.
status: active
updated: 2026-09-29
kind: index
---

# COS Documentation

`main:/docs` описує **поточний COS**, а не історію його міграцій.

## Documentation Framework V2

```text
current code + active tests
        ↓
machine-readable manifests / process definitions
        ↓
generated reference
        ↓
current narrative docs
        ↓
ADR
```

Історичні Wave/V0.x звіти, cutover ledgers і migration snapshots не є canonical knowledge. Вони зберігаються в Git та `archive/documentation/`.

## Типи знань

- Product — призначення, межі та current scope.
- Process — реальні AS-IS / TO-BE бізнес-процеси.
- Architecture — поточні boundaries та invariants.
- Domain — ownership, model, lifecycle, contracts.
- Engineering — як змінювати COS.
- Operations — як запускати та підтримувати COS.
- ADR — довгоживучі рішення.
- Reference — автоматично згенеровані executable facts.

## Перевірка

```bash
npm run docs:generate
npm run docs:generate:check
npm run docs:check
npm run docs:build
```

Generated reference не редагується вручну.
