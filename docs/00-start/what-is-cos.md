---
title: Що таке COS
description: Продуктова й архітектурна роль Company Operating System та короткий шлях до розуміння системи.
status: active
updated: 2026-09-14
kind: concept
---

# Що таке COS

**COS (Company Operating System)** — платформа, яка дає компанії спільний контрольований runtime для виконання бізнес-процесів людьми, software, правилами та AI.

> COS перетворює бізнес-наміри й події на контрольовані, дозволені, спостережувані та пояснювані дії.

COS не є «ще однією CRM». CRM, Sales, Property, Diagnostic, Finance, Support або HR можуть бути окремими Domains.

## Два execution paths

```text
Direct: User → Use Case / Command → Domain → State → Result / Event

Automation: Business Event → Rule / Agent → Action Proposal → Policy → Execution → Result / Audit
```

## Архітектурна формула

```text
Kernel         = HOW execution works
Domain         = WHAT business concept means and WHY rules exist
Application    = orchestration of a concrete business use case
Infrastructure = technical implementation behind ports
Interface      = delivery surface
Bootstrap      = composition root
```

Kernel `0.11.8` дає shared mechanisms для Event/Outbox, Rules, Agents, Actions, Policies, Approvals, Queue, Audit, Tenancy, Modules, LLM governance, resilience та observability.

## Поточні installable Domains

```text
Sales       0.8.6   reference runtime Domain
Diagnostic  0.6.1   executable diagnostic runtime
Property    0.10.0  asset registry + inventory + listing + intelligence + network
```

Exact facts генеруються з current `main` manifests. Деталі: [Current COS Scope](../01-product/current-scope.md).

## Canonical branch

```text
main = executable code + tests + docs + generated-reference inputs + CI/deploy metadata
```

AS-IS твердження повинні підтверджуватися current `main` commit.
