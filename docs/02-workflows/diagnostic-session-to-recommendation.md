---
title: Diagnostic Session → Recommendation
description: Канонічний Diagnostic workflow від methodology version до evidence, evaluation і recommendations.
status: active
updated: 2026-09-14
kind: workflow
---

# Diagnostic Session → Recommendation

## Business goal

Провести відтворювану бізнес-діагностику, де кожен висновок можна простежити до methodology version та evidence, а AI допомагає структурувати дані, але не замінює deterministic evaluation.

## Actors

- methodology author;
- diagnostic operator / interviewer;
- respondent;
- Diagnostic AI boundary;
- deterministic evaluation engine;
- reviewer / decision maker.

## Preparation lifecycle

До початку session methodology проходить окремий lifecycle:

```text
DraftDiagnosticPack
    ↓
validate / revise
    ↓
PublishDiagnosticPack
    ↓
immutable methodology version
```

Session має бути pinned до конкретної published version, а не до mutable «latest».

## Session trigger

```text
StartDiagnosticSession
```

Старт створює version-pinned diagnostic context для target, який діагностується.

## Evidence capture

```text
Interview / source data
    ↓
CaptureDiagnosticEvidence
    ↓
Evidence
    ↓
Facts / Metrics / Assessments
```

Evidence є первинним traceability layer. Derived data без зрозумілого origin не повинні тихо перетворюватися на authoritative conclusions.

## Evaluation

```text
Structured facts / metrics / assessments
    ↓
EvaluateDiagnosticSession
    ↓
Deterministic methodology logic
    ↓
Findings / Hypotheses / Recommendations
```

LLM output не є прямим substitute для deterministic scoring.

## AI-assisted path

```text
Diagnostic context
    ↓
Diagnostic AI gateway
    ↓
Kernel structured LLM contract
    ↓
Provider-neutral execution
    ↓
Structured extraction / interpretation
```

AI може допомогти:

- витягнути facts із тексту;
- нормалізувати відповіді;
- інтерпретувати qualitative evidence;
- підготувати structured input/report material.

AI не має автоматично створювати «істину» без evidence/validation boundary.

## Result lifecycle

Поточні generated application entry points включають:

- `RecordDiagnosticResult`;
- `CompleteDiagnosticSession`;
- `CancelDiagnosticSession`;
- `AcceptDiagnosticRecommendation`.

Повний список див. у [Application Use Cases](../12-reference/application-use-cases.md).

## Workflow

```text
Published Methodology Version
    ↓
Start Session
    ↓
Capture Evidence
    ↓
Structure Facts / Metrics
    ↓
Evaluate
    ↓
Record Results
    ↓
Findings / Hypotheses / Recommendations
    ↓
Complete Session
    ↓
Accept / Reject / Act on Recommendation
```

## Decision points

- methodology version valid/published?
- evidence sufficient?
- confidence/coverage thresholds met?
- evaluation can produce score/finding?
- recommendation traceable to upstream evidence?
- session ready to complete?
- recommendation accepted?

## Failure paths

- draft/unpublished methodology → session start rejected;
- insufficient evidence → evaluation blocked or confidence reduced;
- dangling evidence reference → derived record rejected;
- LLM/schema failure → AI step fails without fabricating deterministic result;
- concurrent write conflict → persistence conflict handling;
- cancelled session → no pretend-success completion.

## Invariants

1. Published methodology version is reproducible.
2. Session is pinned to a version.
3. Derived results remain traceable to evidence/upstream records.
4. Deterministic scoring consumes structured data.
5. LLM transport belongs to Kernel/Infrastructure, prompts/interpretation belong to Diagnostic.
6. Diagnostic does not copy Sales/Finance/HR domain models into itself.
7. Completion freezes a coherent diagnostic result, not an arbitrary snapshot of half-processed input.

## Runtime maturity note

Diagnostic has rich methodology/application logic but its current `0.5.4` module manifest still has partial runtime integration: WEB navigation exists, while runtime module service/API/configuration/capability contributions are not yet declared.

## Code map

```text
app/Domains/Diagnostic/Methodology
app/Domains/Diagnostic/Application/UseCase
app/Domains/Diagnostic/Interview
app/Domains/Diagnostic/Evaluation
app/Domains/Diagnostic/AI
app/Domains/Diagnostic/Report
app/Domains/Diagnostic/Infrastructure
app/Domains/Diagnostic/module.php
```
