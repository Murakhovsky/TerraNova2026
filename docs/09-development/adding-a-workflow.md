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

Кожен `workflow-v2` має matching JSON definition у:

```text
docs/.vitepress/processes/<process-id>.json
```

Process Registry schema `v4` залишається валідною для same-domain workflows. Schema `v5` додає contract-guarded cross-domain steps. Definition фіксує stable process ID, process Domain, business state, actors, steps, edges, primary owner, step Domain, canonical capability або explicit capability gap, criticality та runtime/evidence mappings.

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

## 4. Assign ownership, Domain and capability

Кожний step має одного primary responsible `owner` з declared actors і explicit `domain`.

```text
Step
 ↓
Domain
 ↓
Capability або explicit gap
```

Capability authority — `contributions.capabilities` у module manifest. Checker читає її через current-checkout evidence catalogue, не через generated Markdown.

Для same-domain process step Domain дорівнює process Domain. Якщо step реально переходить в інший Domain, використовуйте schema v5 і canonical foreign-Domain capability. Такий step обов'язково має містити `contract` runtime mapping, який current module evidence підтверджує як `role: requires` від process Domain до target Domain:

```json
{
  "id": "resolve-property",
  "domain": "property",
  "capability": "property.reference",
  "critical": true,
  "runtime": [
    {
      "type": "contract",
      "ref": "Domains\\Property\\Contract\\PropertyReferencePort"
    }
  ]
}
```

Просто поставити чужий `domain` або foreign capability недостатньо: `check-processes.mjs` відхилить cross-domain step без verified contract boundary.

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

Для cross-domain step contract evidence виконує ще одну роль: доводить, що process Domain має право викликати target Domain через declared boundary. Це не дає process Domain ownership над foreign state.

## 7. Render canonical views

Кожна workflow page має рендерити три базові views:

```html
<ProcessDiagram process-id="domain.process-id" />
<ProcessDiagram process-id="domain.process-id" view="ownership" direction="LR" />
<ProcessDiagram process-id="domain.process-id" view="capability" direction="LR" />
```

Cross-domain workflow додатково має рендерити:

```html
<ProcessDiagram process-id="domain.process-id" view="domain" direction="LR" />
```

Views відповідають на різні питання: що відбувається, хто відповідає, яка capability стоїть за step, і через які Domain boundaries проходить процес.

## 8. Connect UI and code

Workflow page повинна вказати UI surfaces та Code map, щоб одна сторінка зв'язувала бізнес, UX, Domain capability, Runtime і implementation. UI click не є business transition сам по собі.

## 9. Verify

Перевірте topology, ownership, step Domain, capability resolution або explicit gap, matching capability debt, cross-domain contract evidence, runtime mappings, derived verification, required `ProcessDiagram` projections, failure paths, idempotency, tenant scope та auditability.

Запустіть:

```bash
php docs/.vitepress/generate-runtime-evidence.php
npm run docs:generate
npm run docs:generate:check
npm run docs:check
npm run docs:build
```

`workflow-v2` не пройде check без matching Process Registry definition, ownership/capability views, valid capability/gap, matching debt item або з фальшивим runtime evidence. Cross-domain workflow також не пройде без schema v5, verified `requires` contract і Domain view.

## Canonical examples

- [Sales Lead → Managed Case](../02-workflows/sales-lead-to-managed-case.md)
- [Sales Request → Property Match](../02-workflows/sales-request-to-property-match.md)
- [Property Submission → Publication](../02-workflows/property-submission-to-publication.md)
- [Diagnostic Session → Recommendation](../02-workflows/diagnostic-session-to-recommendation.md)
- [Business Process Registry](../12-reference/business-processes.md)
- [Capability Debt Backlog](../12-reference/capability-debt.md)
