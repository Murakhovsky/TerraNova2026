---
title: Audit and Diagnostics
description: Пояснюваність runtime, tracing, health та diagnostic boundaries.
status: active
updated: 2026-09-11
kind: runtime
---

# Audit and Diagnostics

COS повинен не лише виконати дію, а й пояснити **чому вона відбулася** і де зламався процес, якщо вона не відбулася.

## Audit vs Logs vs Metrics

Це три різні речі.

### Audit

Business/runtime explanation trail:

- Event;
- decision;
- Action;
- Policy;
- Approval;
- execution result;
- actor / agent;
- correlation.

`Kernel/Audit/AuditEntry` є generic representation, persistence реалізує Infrastructure.

### Logs

Технічні деталі виконання, exception stack, provider errors, worker messages.

### Metrics

Агреговані числові сигнали: latency, throughput, retry rate, denied actions, approval time, queue depth, LLM failures.

Не слід намагатися зробити audit з grep по logs. Це спосіб перетворити incident response на квест.

## Correlation model

Наскрізна операція повинна мати можливість бути відновленою через correlation/causation IDs:

```text
Business Event
   ↓ correlation
Rule / Agent run
   ↓
ActionProposal
   ↓
PolicyEvaluation
   ↓
Approval
   ↓
Queue Job
   ↓
Action execution
   ↓
Result Event
```

## What must be explainable

Для Action треба мати відповідь:

1. хто або що її ініціювало;
2. яка Event була причиною;
3. який Rule/Agent створив proposal;
4. який context був використаний або referenced;
5. яка Policy спрацювала;
6. чому decision був AUTO / APPROVAL_REQUIRED / DENIED;
7. хто схвалив дію;
8. який handler виконував;
9. який external adapter був використаний;
10. який ExecutionResult отримано.

## Agent diagnostics

LLM diagnostics не повинні зберігати raw sensitive context безконтрольно.

Поточна модель передбачає:

- SensitiveContextRedactor;
- structured output validation;
- agent execution records;
- retention/removal sensitive input;
- separation prompt/context від mutation executor.

Корисні agent metrics:

- valid structured response rate;
- rejected proposal rate;
- policy deny rate;
- human approval rate;
- execution success after approval;
- LLM latency/cost;
- repeated/retried runs.

## Queue diagnostics

Operations повинні показувати:

- ready jobs;
- leased jobs;
- retries;
- dead letters;
- oldest pending age;
- worker heartbeat;
- failure reason distribution.

## Module diagnostics

Для кожного Domain module:

- discovered?;
- manifest valid?;
- compatible with Kernel?;
- installed?;
- active for organization?;
- contributions registered?;
- ownership conflicts?;
- required configuration present?;

## Diagnostic Domain

Окремий business Diagnostic domain не треба плутати з technical Kernel diagnostics.

- **Kernel diagnostics**: здоров'я runtime та infrastructure.
- **Business diagnostics**: оцінка процесу компанії, scoring, findings, recommendations.

Детальний business model знаходиться в `docs/architecture/diagnostic-domain-model.md` і `docs/diagnostic/`.

## Incident reading order

```text
1. Health / worker state
2. Event/outbox status
3. Consumer checkpoint
4. Agent/rule decision
5. Policy evaluation
6. Approval state
7. Queue job
8. Action result
9. Integration adapter logs
```

Не починайте з restart worker. Restart без розуміння state іноді просто швидше повторює помилку.

## Invariant

> Будь-яка автономна дія COS має бути не лише executable, а й attributable, traceable та explainable.
