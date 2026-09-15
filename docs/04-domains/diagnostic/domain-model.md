---
title: Diagnostic Domain Model
description: Methodology, session, evidence and traceability model of the Diagnostic bounded context.
status: active
updated: 2026-09-15
kind: domain
---

# Diagnostic Domain Model

Diagnostic є generic bounded context для evidence-based business diagnostics. Він не імпортує Sales, Finance, HR чи інший target Domain як власну модель.

## Core model

```text
Methodology Pack
   ↓ publish immutable version
Diagnostic Session
   ↓
Evidence
   ↓
Facts / Metrics
   ↓
Assessments
   ↓
Findings
   ↓
Hypotheses
   ↓
Recommendations
```

## Methodology ownership

`DiagnosticPack` керує lifecycle methodology pack. Published version immutable; revision створює наступний draft. Session завжди pinned до exact pack id/version, з яким стартувала.

## Traceability

Кожен derived diagnostic record декларує evidence та/або upstream references. Session відхиляє dangling references і повинна дозволяти побудувати повний traceability graph:

```text
Recommendation
  ← Hypothesis / Finding
  ← Assessment
  ← Fact / Metric
  ← Evidence
```

## Neutral target

Methodology визначає target через neutral `DiagnosticTarget`. Target-specific vocabulary живе в methodology pack або target bounded context, а не всередині generic Diagnostic core.

## Core invariants

1. Published methodology version immutable.
2. Session pinned до конкретної methodology version.
3. Derived record має traceable upstream evidence/reference.
4. Diagnostic не мутує target Domain state напряму.
5. Tenant scope та optimistic locking залишаються explicit persistence guarantees.

## Read next

- [Lifecycle & Evaluation](./lifecycle-and-evaluation.md)
- [Contracts & Code Map](./contracts-and-code-map.md)
- [Diagnostic Session → Recommendation](../../02-workflows/diagnostic-session-to-recommendation.md)
