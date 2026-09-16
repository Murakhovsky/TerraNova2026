---
title: Додавання Workflow
description: Як документувати й реалізовувати бізнес-процес через Domain, capability, Runtime, UI та код.
status: active
updated: 2026-09-16
kind: how-to
contract: how-to-v1
---

# Додавання Workflow

Workflow (бізнес-процес) починається з бізнес-мети, а не з нового `Service` class.

## 1. Визначте бізнесовий контракт

Зафіксуйте:

- бізнес-мету;
- actors;
- trigger/input;
- очікуваний результат;
- decision points;
- failure paths;
- owning Domain.

`process_state` описує бізнесовий стан документа: `as-is` або `to-be`. Поле `verification` вручну не задається: його обчислює evidence layer поточного checkout.

## 2. Намалюйте бізнесовий потік

Спочатку моделюйте послідовність бізнесу, а не class graph.

```text
Trigger
  ↓
Business operation / decision
  ↓
State or outcome
```

`sequenceDiagram` використовуйте для реальної взаємодії учасників, `stateDiagram-v2` для сутностей із канонічною моделлю станів.

Повні правила: [Моделювання бізнес-процесів](../02-workflows/business-process-modeling.md).

## 3. Зареєструйте Process

Кожен `workflow-v2` має відповідний JSON definition у:

```text
resources/processes/<process-id>.json
```

Process Registry schema `v4` підтримує same-domain workflows. Schema `v5` додає cross-domain steps, захищені контрактами.

Definition фіксує:

- стабільний `process_id`;
- owning Domain процесу;
- бізнес-стан;
- actors;
- steps та edges;
- primary owner кожного step;
- Domain кожного step;
- capability або явний capability gap;
- criticality;
- runtime/evidence mappings.

Приклад mapped step:

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

## 4. Призначте ownership, Domain і capability

Кожен step має одного primary owner та явний `domain`.

```text
Step
 ↓
Domain
 ↓
Capability або explicit gap
```

Джерелом capability authority є `contributions.capabilities` у manifest модуля.

Для same-domain process Domain step дорівнює Domain процесу.

Для справжнього cross-domain step використовуйте schema `v5`, capability цільового Domain та `contract` runtime mapping, який підтверджує `role: requires` від Domain процесу до цільового Domain.

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

Самого запису чужого `domain` недостатньо: `check-processes.mjs` відхиляє cross-domain step без підтвердженої contract boundary.

## 5. Зафіксуйте capability debt

Якщо потрібна semantic capability ще не оголошена Domain module, не підміняйте її сусідньою capability або permission.

```json
{
  "domain": "sales",
  "capability": null,
  "capability_gap": "missing-domain-capability"
}
```

Кожний `capability_gap` повинен мати один відповідний запис у:

```text
docs/.vitepress/capability-debt.json
```

Приклад:

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

Critical canonical step отримує `high`, non-critical step — `medium`.

Debt закривається цілісно: Domain оголошує capability, Process переходить із gap на capability, а відповідний debt item видаляється.

Поточний стан генерується в [Capability Debt Backlog](../12-reference/capability-debt.md).

## 6. Прив’яжіть runtime evidence

Підтримувані mapping types:

- `use_case` → `Application/UseCase/*.php`;
- `command` → `Application/DTO/*Command.php`;
- `event` → Domain event catalogue;
- `contract` → `cross_domain_contracts` declaration;
- `source` → точний path і, за потреби, symbol.

Derived verification відокремлена від capability coverage:

```text
documented
source-verified
runtime-verified
```

Для cross-domain step `contract` evidence також доводить право Domain процесу звертатись до іншого Domain через задекларовану boundary. Воно не передає ownership чужого стану.

## 7. Відобразіть канонічні представлення

Кожна workflow page рендерить три базові views:

```html
<ProcessDiagram process-id="domain.process-id" />
<ProcessDiagram process-id="domain.process-id" view="ownership" direction="LR" />
<ProcessDiagram process-id="domain.process-id" view="capability" direction="LR" />
```

Cross-domain workflow додатково рендерить:

```html
<ProcessDiagram process-id="domain.process-id" view="domain" direction="LR" />
```

Ці views відповідають на різні питання: що відбувається, хто відповідає, яка capability стоїть за step і які Domain boundaries перетинаються.

## 8. Зв’яжіть UI та код

Workflow page повинна вказати UI surfaces та Code map, щоб одна сторінка зв’язувала:

```text
Business
→ Workflow
→ Domain
→ Capability
→ Runtime
→ UI
→ Code
```

UI click сам по собі не є бізнесовим transition.

## 9. Перевірка

Перевірте:

- topology;
- ownership;
- Domain кожного step;
- capability resolution або explicit gap;
- відповідний capability debt;
- cross-domain contract evidence;
- runtime mappings;
- derived verification;
- обов’язкові `ProcessDiagram` projections;
- failure paths;
- idempotency;
- tenant scope;
- auditability.

Запустіть:

```bash
php docs/.vitepress/generate-runtime-evidence.php
npm run docs:generate
npm run docs:generate:check
npm run docs:check
npm run docs:build
```

`workflow-v2` не пройде перевірку без matching Process Registry definition, ownership/capability views, валідної capability або gap та відповідного debt item. Cross-domain workflow також потребує schema `v5`, підтвердженого `requires` contract і Domain view.

## Канонічні приклади

- [Sales Lead → Managed Case](../02-workflows/sales-lead-to-managed-case.md)
- [Sales Request → Property Match](../02-workflows/sales-request-to-property-match.md)
- [Property Submission → Publication](../02-workflows/property-submission-to-publication.md)
- [Diagnostic Session → Recommendation](../02-workflows/diagnostic-session-to-recommendation.md)
- [Business Process Registry](../12-reference/business-processes.md)
- [Capability Debt Backlog](../12-reference/capability-debt.md)
