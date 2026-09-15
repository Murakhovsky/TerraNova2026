---
title: Diagnostic Lifecycle & Evaluation
description: Methodology publication, session execution, deterministic evaluation and recommendation lifecycle.
status: active
updated: 2026-09-15
kind: domain
---

# Diagnostic Lifecycle & Evaluation

## Lifecycle

```text
Draft methodology
   ↓ validate
Publish immutable version
   ↓
Start version-pinned session
   ↓
Capture evidence-backed facts / metrics
   ↓
Evaluate session
   ↓
Assessments / findings
   ↓
Hypotheses / recommendations
   ↓
Complete and freeze session
```

## Deterministic methodology engine

`Methodology/` компілює та виконує structured methodology. Loader не виконує business interpretation; validator відхиляє broken references, invalid ranges та circular dependencies до evaluation.

Rules читають structured `fact.*`, `metric.*` та `assessment.*` values. Unstructured text або довільний LLM output не є неявним входом deterministic scoring engine.

## Coverage and confidence

Criterion може вимагати minimum coverage/confidence. Якщо threshold не виконано, score не повинен вигадуватися або тихо агрегуватися як повноцінний результат.

## AI boundary

AI може допомагати збирати/структурувати evidence, формувати controlled hypotheses або recommendation proposals, але deterministic facts, methodology version і traceability chain залишаються explicit. LLM не отримує права переписувати methodology semantics після публікації.

## Closed loop

Recommendation outcome повертається в Diagnostic як evidence про результат рекомендації. Це дозволяє оцінювати usefulness methodology, не змішуючи Diagnostic із виконанням target-domain operations.

## Runtime links

- [Agent Runtime](../../06-ai-agents/agent-runtime.md)
- [LLM Governance](../../06-ai-agents/llm-governance.md)
- [Audit & Diagnostics](../../05-runtime/audit-and-diagnostics.md)
- [Event Types](../../12-reference/event-types.md)
