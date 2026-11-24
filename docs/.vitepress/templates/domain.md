---
title: {{title}}
description: {{title}} domain documentation.
status: draft
updated: {{date}}
kind: domain
contract: domain-v1
---

# {{title}}

## Purpose

Поясніть business purpose bounded context.

## Ownership and non-ownership

Чим Domain володіє і що принципово лишається за його межами.

## Domain model

Опишіть canonical concepts, entities/value objects і invariants.

## Lifecycle and use cases

Покажіть основні transitions та application entry points.

## Contracts and persistence

Покажіть ports, adapters, data ownership та cross-domain boundaries.

## Code map

```text
app/Domains/...
```

## Паспорт Domain

Для installable Domain створіть `docs/04-domains/<id>/passport.md` за шаблоном `docs/.vitepress/templates/domain-passport.md` і перевірте `node docs/.vitepress/check-domain-passports.mjs`. Весь executable inventory та версії залишаються в manifest і generated reference.
