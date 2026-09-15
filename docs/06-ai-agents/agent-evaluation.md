---
title: Agent Evaluation
description: Evaluation model for agent quality, reliability, authority safety and operational usefulness in COS.
status: active
updated: 2026-09-15
kind: agent
---

# Agent Evaluation

Agent не можна оцінювати лише за тим, наскільки переконливо він написав відповідь. Для COS важливо, чи він прийняв **корисне, відтворюване і безпечне рішення в межах authority**.

## Evaluation dimensions

### Decision quality

- правильність classification/recommendation;
- relevance до business goal;
- quality of evidence/reasoning output;
- consistency on equivalent inputs.

### Context discipline

- чи використано достатній context;
- чи не підтягнуто зайві sensitive data;
- чи references/provenance коректні;
- чи Agent не вигадує відсутні facts.

### Tool/action safety

- чи proposal відповідає registered capability;
- чи mutation не bypass-ить Policy/Approval;
- чи invalid/unsafe output коректно rejected;
- чи tool arguments проходять schema validation.

### Operational reliability

- structured output validity;
- latency;
- provider/model failure rate;
- fallback behavior;
- token/cost budget;
- retry/idempotency consequences.

### Business usefulness

- accepted recommendation rate;
- approval outcome;
- downstream action/result;
- measured business outcome, де це можливо;
- false-positive/false-negative cost.

## Evaluation set

Для meaningful Agent потрібен versioned evaluation set:

```text
Input facts/context
+ expected constraints
+ acceptable decisions
+ forbidden decisions
+ expected authority path
+ outcome rubric
```

Не всі кейси мають одну exact відповідь. Rubric може перевіряти дозволений set рішень та invariants.

## Required adversarial cases

Перевіряйте щонайменше:

- missing context;
- contradictory facts;
- malicious/untrusted text in context;
- invalid structured output;
- proposal outside capability;
- request for unauthorized mutation;
- provider timeout/fallback;
- duplicated execution request;
- sensitive-data leakage.

## Version correlation

Evaluation result має бути прив'язаний до agent definition, context schema, instruction/prompt version, output schema, model/provider route та relevant policy version.

## Production feedback

Production telemetry не замінює offline evaluation, але доповнює її:

```text
proposal → policy → approval → execution → outcome
```

Саме повний ланцюг показує, чи Agent реально корисний, а не просто генерує інтелектуально оформлену зайнятість.

## Related

- [Agent Runtime](./agent-runtime.md)
- [Memory Model](./memory-model.md)
- [Audit & Diagnostics](../05-runtime/audit-and-diagnostics.md)
