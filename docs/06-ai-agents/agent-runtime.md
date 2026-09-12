---
title: Agent Runtime
description: Як AI Agent приймає рішення в COS без прямого доступу до mutation layer.
status: active
updated: 2026-09-12
kind: agent
---

# Agent Runtime

Agent у COS є **decision component**, а не автономним root user системи.

Реальна реалізація знаходиться в `app/Kernel/Agent` і включає `AgentDefinition`, `AgentInvocation`, `AgentExecution`, `AgentResult`, `AgentRuntime`, routed context builder, sensitive context redactor, structured decision validator та adapter до governed structured LLM runtime.

## Runtime flow

```text
Event / Job
   ↓
AgentDefinition
   ↓
Domain context builder
   ↓
SensitiveContextRedactor
   ↓
StructuredAgentLlmClient
   ↓
GovernedStructuredLlmClient
   ↓
Budget + Routing + Provider/Fallback
   ↓
StructuredDecisionValidator
   ↓
AgentResult
   ↓
ActionProposal(s)
   ↓
normal Action → Policy → Approval → Queue → Execution
```

## AgentDefinition

Definition описує стабільний contract Agent-а:

- identity;
- purpose;
- model/runtime configuration;
- expected structured output;
- allowed proposal/action vocabulary;
- domain ownership.

Business-specific agent definitions належать Domain, generic execution — Kernel.

## Context ownership

Agent не має самостійно ходити по database/integrations за будь-якими даними, які йому захотілися.

Domain context builder визначає:

- які факти потрібні;
- як вони tenant-scope-яться;
- які поля дозволено передати LLM;
- як формується compact decision context.

`RoutedAgentContextBuilder` дозволяє Kernel направити запит до правильного domain-owned builder без знання Sales/Finance/etc.

## Sensitive context

Перед передачею та збереженням agent input проходить redaction.

`SensitiveContextRedactor` є safety/privacy boundary, а не cosmetic preprocessing.

Принцип:

```text
minimum necessary context
> full database dump
```

## Governed LLM boundary

Починаючи з Kernel V0.10, Agent runtime не звертається напряму до concrete provider client.

`StructuredAgentLlmClient` переводить Agent request у `StructuredLlmRequest`, який проходить через shared LLM governance layer.

Для governed call важливі:

- `organizationId` — tenant budget/accounting scope;
- `useCase` — routing/metrics scope;
- `correlationId` — traceability;
- requested model — compatibility hint, якщо немає explicit use-case routing policy.

Explicit use-case routing policy має пріоритет над model hint Agent-а.

Детально: [LLM Governance](llm-governance.md).

## Structured output

LLM response не вважається валідним decision лише тому, що JSON парситься.

`StructuredDecisionValidator` повинен відхилити:

- unknown action type;
- malformed payload;
- missing required fields;
- forbidden/unsupported proposal;
- schema mismatch;
- інші contract violations.

Invalid response не створює mutation.

## Proposal-only rule

Agent може запропонувати Action, але не може викликати Action executor напряму.

Це означає:

```text
LLM says “send message”
≠
message sent
```

Між ними ще стоять:

```text
validated proposal
→ Action
→ Policy
→ optional Approval
→ Queue
→ Handler
```

LLM routing/fallback також не змінює це правило. Інший provider може допомогти отримати decision, але не може обійти Policy.

## Deterministic vs agentic decisions

Якщо рішення можна надійно виразити Rule, воно не повинно автоматично ставати LLM task.

Використовувати Agent там, де потрібні:

- interpretation;
- prioritization;
- synthesis;
- natural-language understanding;
- ambiguous context reasoning;
- recommendation among bounded choices.

Використовувати Rule для:

- thresholds;
- exact states;
- mandatory fields;
- deterministic eligibility;
- hard compliance constraints.

## Failure model

Agent runtime має розрізняти щонайменше:

- invalid structured decision;
- budget denied;
- retryable provider failure;
- non-retryable provider failure;
- exhausted provider routes;
- downstream Action/Policy failure.

Retryable LLM provider failure може привести до configured fallback route. Non-retryable provider failure не повинен тихо маскуватися переходом на інший provider.

## Testing agents

Agent testing має розділяти:

1. context builder tests;
2. redaction tests;
3. structured schema validation;
4. fixture-based LLM decision tests;
5. LLM routing/budget/fallback tests;
6. policy behavior for proposed actions;
7. end-to-end action execution tests окремо.

Не треба тестувати весь COS одним prompt і радіти, що він «схоже відповів правильно».

## Metrics

Мінімум:

- invocation count;
- valid/invalid output rate;
- LLM latency;
- input/output tokens;
- LLM cost;
- fallback count;
- budget denials;
- proposal distribution;
- denied actions;
- approval-required actions;
- eventual execution success;
- retries/failures.

## Invariant

> Agent має право думати в межах context contract. Право діяти визначає Kernel Policy runtime.
