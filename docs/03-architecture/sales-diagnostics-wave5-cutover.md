---
title: Перенесення Sales Diagnostics у Symfony
description: "П'ята хвиля другої фази міграції COS: канонічний Symfony boundary для створення діагностики, інтерв'ю, evidence pipeline, deterministic assessment, findings, recommendations та Recommendation → Action."
status: active
updated: 2026-09-18
kind: architecture
contract: architecture-v1
---

# Перенесення Sales Diagnostics у Symfony

Wave 5 робить Symfony канонічним HTTP/application boundary для Sales Diagnostics, зберігаючи наявний Diagnostic Domain і MySQL persistence як джерело істини.

## Сценарії Wave 5

| Roadmap | Сценарій | Канонічний API | Boundary |
| --- | --- | --- | --- |
| 22 | Створення діагностики | `POST /api/v1/diagnostics` | `StartDiagnosticCommand` |
| 23 | Інтерв'ю | `GET .../interview/next`, `POST .../interview/answers` | Query + command |
| 24 | Evidence → Fact/Metric | `POST .../evidence` | `DiagnosticEvidencePipeline` |
| 25 | Assessment | `POST .../complete`, `GET .../assessment` | deterministic evaluation |
| 26 | Findings | `GET .../findings` | report projection |
| 27 | Recommendations | `GET .../recommendations` | recommendation projection |
| 28 | Recommendation → Action | `POST .../recommendations/{recommendationId}/action` | governed COS Action |

## Канонічний шлях

```text
UI/API
  ↓
Symfony V1
  ↓
CommandBus / QueryBus
  ↓
Diagnostic Application
  ↓
Diagnostic Domain
  ↓
Ports
  ↓
Legacy MySQL adapters
```

Другий Diagnostic engine не створюється. Наявні methodology, interview extraction, deterministic criteria/rule evaluation, findings та recommendation lifecycle залишаються авторитетними.

## Evidence pipeline

Evidence підтримує `interview`, `system_data` для CRM і metrics, `document`, `external_source`, `observation` та `survey`.

Структурований evidence може містити `facts[]` і `metrics[]`. Кожне похідне значення зберігає посилання на Evidence, а суперечливі факти фіксуються в `contradictions` замість тихого перезапису без traceability.

AI не обчислює score:

```text
Evidence
  ↓
Fact / Metric
  ↓
Criteria
  ↓
Rules
  ↓
Assessment
  ↓
Finding
  ↓
Recommendation
```

## Гарантії write-сценаріїв

Mutation endpoints вимагають:

- authenticated tenant context;
- активний модуль Diagnostic;
- tenant permission;
- CSRF під час strangler phase;
- correlation id;
- `X-Idempotency-Key`.

Створення використовує deterministic session id. Interview retries зберігають idempotency key. Evidence отримує deterministic evidence id. Completion є terminal-state idempotent. Recommendation → Action використовує Kernel Action idempotency та режим `APPROVAL_REQUIRED`.

## Recommendation → Action

Recommendation не є кінцевим артефактом:

```text
Finding
  ↓
Recommendation
  ↓
COS Action
  ↓
Approval
  ↓
Execution
  ↓
Outcome measurement
```

Diagnostic module реєструється у Symfony `DomainModuleRegistry`, тому його Action handler проходить тим самим governed execution path, що й Sales, і не пише business tables напряму.
