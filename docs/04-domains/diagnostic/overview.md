---
title: Diagnostic Domain Overview
description: Methodology, evidence, deterministic evaluation та AI boundary Diagnostic domain.
status: active
updated: 2026-09-11
kind: domain
---

# Diagnostic Domain Overview

Diagnostic — generic bounded context для evidence-based business diagnostics.

## Ownership

Diagnostic володіє:

- methodology packs;
- immutable published versions;
- diagnostic sessions;
- evidence;
- facts і metrics;
- assessments;
- findings;
- hypotheses;
- recommendations;
- methodology validation/compiler;
- deterministic scoring/evaluation;
- diagnostic interview/report lifecycle;
- AI-assisted extraction/interpretation boundary.

Він не володіє Sales, Finance, HR або іншою target domain model.

## Core lifecycle

```text
draft methodology
→ validate
→ publish immutable version
→ start version-pinned session
→ capture evidence
→ facts / metrics
→ deterministic evaluation
→ findings / hypotheses / recommendations
→ complete and freeze
```

## Traceability

Derived records повинні посилатися на evidence і/або upstream records. Dangling references відхиляються.

Diagnostic без traceability дуже швидко перетворюється на дорогий генератор переконливих абзаців, а нам цього добра й без системи вистачає.

## Deterministic engine

Scoring/rules працюють на structured facts, metrics та assessments. LLM output не входить напряму в deterministic scoring як магічна істина.

Coverage/confidence thresholds можуть блокувати score/finding, якщо даних недостатньо.

## AI boundary

Diagnostic використовує provider-neutral `Kernel\\Llm` structured contract, а не маскує звичайний LLM call під Agent runtime.

```text
Diagnostic use case
→ Diagnostic AI gateway
→ Kernel\\Llm request/response contract
→ Infrastructure\\Llm transport
→ provider
```

Prompts, schemas, methodology context і interpretation належать Diagnostic.

## Structure

Поточні areas: `Model`, `Methodology`, `Application`, `Automation`, `AI`, `Interview`, `Evaluation`, `Report`, `Infrastructure`.

## Persistence guarantees

Published methodology version має бути reproducible; session pinned до конкретної версії. Persistence tenant-scoped і використовує optimistic locking там, де concurrent session writes можуть конфліктувати.

## Module status

`module.php` існує (`diagnostic`, version `0.5.4`, kernel constraint `^0.7.1`), але runtime module service/capabilities наразі не задекларовані.

## Normative references

- `docs/architecture/diagnostic-domain-model.md`
- `docs/diagnostic/diagnostic-pack.schema.json`
- `docs/diagnostic/methodology-pack-phase2.schema.json`