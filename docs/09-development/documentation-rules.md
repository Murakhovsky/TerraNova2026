---
title: Правила документації
description: Documentation Framework V2: current truth, generated reference, ADR та архів історичних матеріалів.
status: active
updated: 2026-09-29
kind: development
---

# Правила документації

## Основне правило

Active `docs/` відповідає на питання **«як COS працює зараз?»**.

Git та `archive/documentation/` відповідають на питання **«як ми сюди прийшли?»**.

Не змішуйте ці два завдання.

## Source of truth

```text
code + active tests
  ↓
manifests / processes
  ↓
generated reference
  ↓
narrative docs
  ↓
ADR
```

Наративний Markdown не є dependency application test.

## Заборонений патерн

Не створюйте нові active сторінки на кшталт:

```text
web-v0.18.md
growth-v0510.md
wave15-final.md
phase-4-closure.md
```

Якщо змінився current architecture document, оновіть його. Якщо прийнято довгоживуче рішення, створіть ADR. Якщо це одноразовий звіт міграції, він не належить до canonical docs.

## Типи сторінок

Canonical contracts залишаються: `concept-v1`, `workflow-v2`, `architecture-v1`, `domain-v1`, `how-to-v1`, `reference-v1`.

Workflow використовує `process_state: as-is|to-be`. Runtime evidence визначається tooling, а не заявою автора.

## Generated reference

`docs/12-reference` генерується з executable source і не редагується вручну.

## ADR

ADR зберігає рішення, а не snapshot реалізації:

```text
Context → Decision → Rationale → Alternatives → Consequences → Verification
```

## CI

```bash
npm run docs:generate:check
npm run docs:check
npm run docs:build
```

Application tests не повинні шукати речення у Markdown або перевіряти конкретний GitHub workflow YAML.
