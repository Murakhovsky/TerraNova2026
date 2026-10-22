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

## Стратегічний рівень

[Стратегічна хартія COS v0.1 (Founding Draft)](./00-start/strategic-charter.md) визначає довгостроковий напрям, обмеження автономності, незалежність від постачальників і пріоритет вимірюваних бізнес-результатів. Вона описує **TO-BE** та не підміняє чинну ієрархію виконуваних фактів **AS-IS**, наведену вище.

- [Стратегічна дорожня карта COS](./00-start/strategic-roadmap.md) — етапи, пріоритети, критерії готовності та економічні пілоти.
- [Реєстр стратегічних рішень COS](./00-start/strategic-decision-register.md) — власники рішень, альтернативи, ризики, затвердження та умови перегляду.

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
