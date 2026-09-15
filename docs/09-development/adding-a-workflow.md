---
title: Adding a Workflow
description: How to document and implement a business workflow across Domain, Runtime, UI and code boundaries.
status: active
updated: 2026-09-15
kind: how-to
contract: how-to-v1
---

# Adding a Workflow

Workflow починається з business goal, а не з нового Service class.

## 1. Define business contract

Зафіксуйте:

- business goal;
- actors;
- trigger/input;
- expected output/outcome;
- decision points;
- failure paths.

Одразу визначте тільки **business state**:

- `as-is` — поточний реальний процес;
- `to-be` — цільовий процес.

Не записуйте `runtime-verified` у `process_state` і не додавайте authored `verification` у Process Registry. Verification обчислюється з current-checkout evidence.

## 2. Draw the business flow

Канонічний workflow має derived Mermaid diagram з Process Registry. Спочатку моделюйте business sequence, а не class graph.

```text
Trigger
  ↓
Business operation / decision
  ↓
State or outcome
```

Для actor/system interaction використовуйте `sequenceDiagram` лише коли interaction semantics явно описані. Для lifecycle entity — `stateDiagram-v2` лише коли існує canonical state model. Generic process steps не треба насильно перетворювати на інший тип діаграми.

Повні conventions: [Business Process Modeling](../02-workflows/business-process-modeling.md).

## 3. Register the process

Кожен `workflow-v2` має matching JSON definition у:

```text
docs/.vitepress/processes/<process-id>.json
```

Schema `v3` фіксує:

- stable process ID;
- Domain owner;
- business state (`as-is` / `to-be`);
- workflow page;
- trigger, actors та outcomes;
- steps і edges;
- critical steps;
- runtime/evidence mappings.

Runtime mapping types:

- `use_case` → Domain `Application/UseCase/*.php`;
- `command` → Domain `Application/DTO/*Command.php`;
- `event` → explicit Domain event catalogue;
- `contract` → canonical module `cross_domain_contracts` declaration;
- `source` → exact repository path + optional symbol.

Не вигадуйте event/command/contract names «по сенсу». Якщо Runtime Evidence Resolver їх не знає, використовуйте реальний source mapping або спершу виправте canonical executable catalogue.

## 4. Assign ownership

Кожний step має одного primary responsible `owner` з declared actors.

Для кожного state change визначте Domain owner. Cross-domain workflow може координувати кілька Domains, але не створює shared ownership. Cross-domain boundary документуйте через canonical contract, якщо він уже існує в Architecture Graph.

## 5. Map execution evidence

Narrative, Process Registry і executable boundaries зв'яжіть явними mappings:

```text
Process step
   ↓
Use Case / Command / Source
   ↓
Event / Contract when canonical runtime evidence exists
   ↓
Derived verification
```

Evidence має дві сили:

- `source`: use case, command або exact source існує в current checkout;
- `runtime`: event/contract присутній у canonical runtime/architecture catalogue.

Derived verification:

- `documented` — не всі critical steps мають resolvable evidence;
- `source-verified` — усі critical steps source-verified;
- `runtime-verified` — усі critical steps мають runtime-strength evidence.

`runtime-verified` тут структурний статус. Він не означає, що production trace фактично пройшов через увесь workflow.

## 6. Connect UI and code

Workflow page повинна вказати UI surfaces та Code map, щоб одна сторінка зв'язувала бізнес, UX, Runtime і implementation.

UI click не є business transition сам по собі. Diagram має називати business action/result, якщо UI лише доставляє intent.

## 7. Verify

Перевірте:

- happy path;
- forbidden transitions;
- duplicate delivery/idempotency;
- external failure/retry;
- tenant scope;
- auditability;
- відповідність Registry topology narrative тексту;
- відповідність `process_state` фактичному business state;
- існування всіх runtime mappings;
- derived verification у generated Business Process Registry.

Запустіть:

```bash
php docs/.vitepress/generate-runtime-evidence.php
npm run docs:generate
npm run docs:generate:check
npm run docs:check
npm run docs:build
```

`workflow-v2` не пройде check без `process_state`, ProcessDiagram, matching Process Registry definition або з фальшивим runtime evidence.

## Canonical examples

- [Sales Lead → Managed Case](../02-workflows/sales-lead-to-managed-case.md)
- [Property Submission → Publication](../02-workflows/property-submission-to-publication.md)
- [Diagnostic Session → Recommendation](../02-workflows/diagnostic-session-to-recommendation.md)
- [Business Process Registry](../12-reference/business-processes.md)
