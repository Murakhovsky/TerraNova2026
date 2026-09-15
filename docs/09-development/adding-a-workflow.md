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

Зафіксуйте business goal, actors, trigger/input, outcome, decision points, failure paths та owning Domain. `process_state` описує лише бізнес-стан: `as-is` або `to-be`. Authored `verification` заборонена: її обчислює current-checkout evidence layer.

## 2. Draw the business flow

Спочатку моделюйте business sequence, а не class graph.

```text
Trigger
  ↓
Business operation / decision
  ↓
State or outcome
```

Для interaction використовуйте `sequenceDiagram` лише коли interaction semantics явно описані. Для entity lifecycle — `stateDiagram-v2` лише коли існує canonical state model. Повні conventions: [Business Process Modeling](../02-workflows/business-process-modeling.md).

## 3. Register the process

Кожен `workflow-v2` має matching canonical JSON definition у platform Process Registry:

```text
resources/processes/<process-id>.json
```

`resources/processes` є source of truth. `Kernel\\Process\\ProcessRegistryInterface` робить ту саму модель доступною runtime consumers, а Documentation/Visualization лише проєктують її.

Current Process Registry schema `v4` фіксує stable process ID, Domain, business state, actors, steps, edges, primary owner, step Domain, canonical capability або explicit capability gap, criticality та runtime/evidence mappings.

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

Якщо semantic capability реально ще не оголошена Domain module, не підміняйте її permission або сусідньою capability:

```json
{
  "domain": "sales",
  "capability": null,
  "capability_gap": "missing-domain-capability"
}
```

Це architecture debt, але сам Process Registry володіє лише фактом gap.

## 4. Assign ownership and capability

Кожний step має одного primary responsible `owner` з declared actors і explicit `domain`.

```text
Step
 ↓
Domain
 ↓
Capability або explicit gap
```

Capability authority — `contributions.capabilities` у module manifest. Checker читає її через current-checkout evidence catalogue, не через generated Markdown. Schema v4 не дозволяє step мовчки переходити в інший Domain.

## 5. Register capability debt

Кожний `capability_gap` мусить мати рівно один matching item у:

```text
docs/.vitepress/capability-debt.json
```

Capability Debt Registry schema `v1` зберігає remediation metadata, а не дублює process truth:

```json
{
  "id": "sales.lead-to-managed-case:intake",
  "process_id": "sales.lead-to-managed-case",
  "step_id": "intake",
  "gap_type": "missing-domain-capability",
  "owner_domain": "sales",
  "severity": "high",
  "resolution": "declare-domain-capability",
  "target_capability": "sales.lead.intake"
}
```

Severity policy детермінований: critical canonical step → `high`, non-critical canonical step → `medium`. Target capability мусить бути в namespace owning Domain і ще не існувати в module capability authority.

Debt закривається тільки разом: Domain оголошує target capability, process step переходить з gap на capability, matching debt item видаляється. Generated [Capability Debt Backlog](../12-reference/capability-debt.md) покаже залишок автоматично.

## 6. Map execution evidence

Runtime mapping types:

- `use_case` → Domain `Application/UseCase/*.php`;
- `command` → Domain `Application/DTO/*Command.php`;
- `event` → explicit Domain event catalogue;
- `contract` → canonical module `cross_domain_contracts` declaration;
- `source` → exact repository path + optional symbol.

Evidence має `source` або `runtime` strength. Derived verification залишається окремою від capability coverage: `documented`, `source-verified`, `runtime-verified`.

## 7. Render the three canonical views

Workflow page має рендерити:

```html
<ProcessDiagram process-id="domain.process-id" />
<ProcessDiagram process-id="domain.process-id" view="ownership" direction="LR" />
<ProcessDiagram process-id="domain.process-id" view="capability" direction="LR" />
```

Вони відповідають на три різні питання: що відбувається, хто відповідає, яка Domain capability стоїть за step або де capability model має gap.

## 8. Connect UI and code

Workflow page повинна вказати UI surfaces та Code map, щоб одна сторінка зв'язувала бізнес, UX, Domain capability, Runtime і implementation. UI click не є business transition сам по собі.

## 9. Verify

Перевірте topology, ownership, step Domain, capability resolution або explicit gap, matching capability debt, runtime mappings, derived verification, три `ProcessDiagram` projections, failure paths, idempotency, tenant scope та auditability.

Запустіть:

```bash
php docs/.vitepress/generate-runtime-evidence.php
npm run docs:generate
npm run docs:generate:check
npm run docs:check
npm run docs:build
```

`workflow-v2` не пройде check без matching Process Registry definition, ownership/capability views, valid capability/gap, matching debt item або з фальшивим runtime evidence.

## Canonical examples

- [Sales Lead → Managed Case](../02-workflows/sales-lead-to-managed-case.md)
- [Property Submission → Publication](../02-workflows/property-submission-to-publication.md)
- [Diagnostic Session → Recommendation](../02-workflows/diagnostic-session-to-recommendation.md)
- [Business Process Registry](../12-reference/business-processes.md)
- [Capability Debt Backlog](../12-reference/capability-debt.md)
