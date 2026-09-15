---
title: Adding a Workflow
description: How to document and implement a business workflow across Domain capability, Runtime, UI and code boundaries.
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
- failure paths;
- owning Domain.

Одразу визначте тільки **business state**:

- `as-is` — поточний реальний процес;
- `to-be` — цільовий процес.

Не записуйте authored `verification`. Verification обчислюється з current-checkout evidence.

## 2. Draw the business flow

Канонічний workflow має derived Mermaid diagram з Process Registry. Спочатку моделюйте business sequence, а не class graph.

```text
Trigger
  ↓
Business operation / decision
  ↓
State or outcome
```

Для interaction використовуйте `sequenceDiagram` лише коли interaction semantics явно описані. Для entity lifecycle — `stateDiagram-v2` лише коли існує canonical state model.

Повні conventions: [Business Process Modeling](../02-workflows/business-process-modeling.md).

## 3. Register the process

Кожен `workflow-v2` має matching JSON definition у:

```text
docs/.vitepress/processes/<process-id>.json
```

Schema `v4` фіксує:

- stable process ID;
- process Domain;
- business state (`as-is` / `to-be`);
- workflow page;
- trigger, actors та outcomes;
- steps і edges;
- primary owner;
- step domain;
- canonical capability або explicit capability gap;
- critical steps;
- runtime/evidence mappings.

Мінімальний mapped step:

```json
{
  "id": "inventory",
  "label": "Create Inventory Item",
  "kind": "state",
  "owner": "inventory owner",
  "domain": "property",
  "capability": "property.inventory",
  "critical": true,
  "runtime": [
    {
      "type": "source",
      "path": "app/Domains/Property/Application/Service/PropertyCanonicalRuntimeService.php",
      "symbol": "createInventory"
    }
  ]
}
```

Якщо semantic capability реально ще не оголошена Domain module, не підміняйте її permission або випадковою сусідньою capability:

```json
{
  "domain": "sales",
  "capability": null,
  "capability_gap": "missing-domain-capability"
}
```

Це architecture debt, а не причина брехати в registry.

## 4. Assign ownership and capability

Кожний step має одного primary responsible `owner` з declared actors.

Далі вкажіть:

```text
Step
 ↓
Domain
 ↓
Capability або explicit gap
```

Capability authority — `contributions.capabilities` у module manifest. Checker читає її через current-checkout evidence catalogue, не через generated Markdown.

Поточна schema v4 не дозволяє step мовчки переходити в інший Domain. Cross-domain step потребує наступного explicit registry contract.

## 5. Map execution evidence

Runtime mapping types:

- `use_case` → Domain `Application/UseCase/*.php`;
- `command` → Domain `Application/DTO/*Command.php`;
- `event` → explicit Domain event catalogue;
- `contract` → canonical module `cross_domain_contracts` declaration;
- `source` → exact repository path + optional symbol.

Не вигадуйте event/command/contract names «по сенсу». Якщо Evidence Resolver їх не знає, використовуйте реальний source mapping або спершу виправте executable authority.

Evidence має дві сили:

- `source`: use case, command або exact source існує в current checkout;
- `runtime`: event/contract присутній у canonical runtime/architecture catalogue.

Derived verification:

- `documented` — не всі critical steps мають resolvable evidence;
- `source-verified` — усі critical steps source-verified;
- `runtime-verified` — усі critical steps мають runtime-strength evidence.

## 6. Render the three canonical views

Workflow page має рендерити:

```html
<ProcessDiagram process-id="domain.process-id" />
<ProcessDiagram process-id="domain.process-id" view="ownership" direction="LR" />
<ProcessDiagram process-id="domain.process-id" view="capability" direction="LR" />
```

Вони відповідають на три різні питання:

1. що відбувається;
2. хто відповідає;
3. яка Domain capability стоїть за step або де capability model має gap.

## 7. Connect UI and code

Workflow page повинна вказати UI surfaces та Code map, щоб одна сторінка зв'язувала бізнес, UX, Domain capability, Runtime і implementation.

UI click не є business transition сам по собі. Diagram має називати business action/result, якщо UI лише доставляє intent.

## 8. Verify

Перевірте:

- happy path;
- forbidden transitions;
- duplicate delivery/idempotency;
- external failure/retry;
- tenant scope;
- auditability;
- Registry topology;
- ownership;
- step domain;
- capability resolution або explicit gap;
- runtime mappings;
- derived verification;
- три `ProcessDiagram` projections.

Запустіть:

```bash
php docs/.vitepress/generate-runtime-evidence.php
npm run docs:generate
npm run docs:generate:check
npm run docs:check
npm run docs:build
```

`workflow-v2` не пройде check без matching Process Registry definition, ownership view, capability view, valid capability/gap або з фальшивим runtime evidence.

## Canonical examples

- [Sales Lead → Managed Case](../02-workflows/sales-lead-to-managed-case.md)
- [Property Submission → Publication](../02-workflows/property-submission-to-publication.md)
- [Diagnostic Session → Recommendation](../02-workflows/diagnostic-session-to-recommendation.md)
- [Business Process Registry](../12-reference/business-processes.md)
