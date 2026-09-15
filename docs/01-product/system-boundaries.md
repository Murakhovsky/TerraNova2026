---
title: COS System Boundaries
description: What belongs to COS, what belongs to Domains and integrations, and what COS deliberately does not own.
status: active
updated: 2026-09-15
kind: product
---

# COS System Boundaries

Boundary потрібна не для краси діаграми. Вона визначає, де живе truth і хто має право її змінювати.

## COS owns

На product/platform level COS володіє моделлю керованого виконання:

- module/runtime composition;
- execution context and lifecycle;
- generic events/durable delivery mechanics;
- policies, approvals and permissions mechanics;
- queue/job execution mechanics;
- audit/diagnostic mechanisms;
- Agent/tool governance mechanics;
- common interface/integration patterns.

## Domains own

Business Domains володіють semantic truth:

```text
Sales      → demand lifecycle
Property   → real-estate asset/commercial presentation truth
Diagnostic → methodology/session/evidence/evaluation truth
```

Supporting bounded areas можуть володіти своїми semantics, але не повинні ставати generic dumping ground.

## Interfaces own delivery, not business truth

```text
Web / API / Telegram / CLI / Worker
          ↓
Application boundary
          ↓
Domain operation
```

Interface може format input/output, authenticate transport context або render UI. Він не повинен самостійно вирішувати canonical business transition.

## Integrations own translation

Provider adapter володіє protocol/vocabulary translation, retry/idempotency details і external transport. Він не переписує Domain vocabulary під Salesforce/RESO/Telegram/іншого постачальника.

## COS deliberately does not mean

COS не є:

- універсальною shared database, яку всі модулі мутують напряму;
- одним глобальним CRM aggregate для всіх бізнесових понять;
- LLM orchestration layer без deterministic business core;
- workflow engine, який сам володіє всіма Domain states;
- frontend framework;
- collection of provider SDKs.

## Boundary test

Перед додаванням capability задайте п'ять питань:

1. Який business concept ми змінюємо?
2. Який Domain володіє його truth?
3. Чи це generic runtime mechanism замість Domain semantics?
4. Чи provider/interface detail випадково просочується всередину Domain?
5. Який explicit contract перетинає boundary?

Якщо відповідь «ну нехай поки буде в shared service», boundary ще не визначена.

## Maps

- [System Map](../03-architecture/system-map.md)
- [Domain Map](../03-architecture/domain-map.md)
- [Cross-Domain Contracts](../03-architecture/cross-domain-contracts.md)
