---
title: COS Vision & Core Principles
description: Product intent and non-negotiable principles that define Company Operating System.
status: active
updated: 2026-09-15
kind: product
---

# COS Vision & Core Principles

COS існує не як ще одна CRM або набір AI-чатів. Його product goal — дати компанії **керовану операційну систему**, де business work має explicit ownership, state, decisions, authority, execution та audit trail.

## Product vision

```text
Business intent / fact
        ↓
Known workflow
        ↓
Semantic Domain owner
        ↓
Deterministic or agent-assisted decision
        ↓
Explicit authority
        ↓
Controlled execution
        ↓
Observable result
```

Людина, automation і Agent повинні працювати через одну систему бізнесових правил, а не через три паралельні реальності.

## Core principles

### 1. Business first

Architecture починається з business operation/workflow. Class hierarchy не є product model.

### 2. Domain ownership

Кожен meaningful business state має одного semantic owner. Інші Domains можуть request, reference і react, але не мутують чужу truth напряму.

### 3. Kernel is mechanism, not business

Kernel дає execution, events, policy, approvals, queue, audit та module/runtime mechanics. Він не вирішує, чи Lead qualified, Property sold або diagnostic finding valid.

### 4. AI proposes; authority decides

Agent може інтерпретувати context і запропонувати дію. Право на mutation визначають Policy, permissions і, де потрібно, Human Approval.

### 5. State changes are explainable

Для важливої операції система повинна дозволяти відновити: хто/що ініціював дію, який Domain володів рішенням, який context використано, що дозволило mutation і який результат отримано.

### 6. Durable by default where consequence matters

Події, asynchronous execution і external side effects повинні мати explicit delivery/idempotency semantics там, де втрата або дублювання операції створює business risk.

### 7. Interfaces do not own rules

Web, API, Telegram, workers та зовнішні adapters є delivery surfaces. Вони не створюють окремі версії business logic.

### 8. Exact facts should be generated

Versions, routes, commands, events і capabilities не повинні дублюватися вручну по narrative docs. Executable truth генерується з current checkout.

## Product consequence

COS не намагається втиснути кожну business capability в один мегамодуль. Product масштабується через bounded Domains + shared governed runtime.

## Read next

- [Capabilities](./capabilities.md)
- [Actors & Authority](./actors-and-authority.md)
- [System Boundaries](./system-boundaries.md)
- [COS Mental Model](../00-start/mental-model.md)
