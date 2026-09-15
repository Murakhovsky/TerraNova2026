---
title: Business Process Modeling
description: Canonical rules for modeling real COS business processes with Process Registry, Domain capabilities, Mermaid and evidence-backed verification.
status: active
updated: 2026-09-15
kind: concept
contract: concept-v1
---

# Business Process Modeling

COS documentation treats a business process as an operational model, not as decorative documentation. The canonical topology lives in the **Process Registry** and Mermaid is a derived projection. Visualization V0.5 separated business truth from runtime evidence; DOC V0.15 adds the explicit bridge from each process step to its owning Domain capability.

## Source-of-truth chain

```text
Business meaning
        ↓
Process Registry definition
steps + owners + domain + capability/gap + edges + criticality + runtime mappings
        ↓                         ↓
Module capability authority      Runtime Evidence Resolver
app/Domains/*/module.php         current source + events + contracts
        ↓                         ↓
Capability coverage              Derived verification
        └──────────────┬──────────┘
                       ↓
              ProcessDiagram + Reference
                       ↓
                 Mermaid / VitePress
```

A workflow page remains the human narrative around the process, but it does not manually duplicate the same core topology, ownership, capability claim or verification claim.

## Three independent dimensions

### Business state

Every canonical workflow page declares `process_state`, and the matching registry definition declares the same `state`.

| State | Meaning |
| --- | --- |
| `as-is` | Реальний поточний бізнес-процес. Він може містити manual steps і не зобов'язаний бути повністю automated. |
| `to-be` | Цільовий процес, який ще не можна читати як поточну поведінку компанії або COS. |

`status` описує документ. `process_state` описує бізнес-процес.

### Capability coverage

Кожний step schema v4 має explicit `domain` і одне з двох:

```json
{
  "domain": "property",
  "capability": "property.inventory"
}
```

або чесний gap:

```json
{
  "domain": "sales",
  "capability": null,
  "capability_gap": "missing-domain-capability"
}
```

Capability вважається canonical лише тоді, коли вона оголошена module contribution у `app/Domains/*/module.php`. `check-processes.mjs` перевіряє це через current-checkout evidence catalogue, а не через generated Markdown.

Capability gap не означає, що крок не реалізований. Він означає, що Domain capability vocabulary ще не описує цей business operation достатньо точно.

### Derived verification

Verification не записується в process JSON. Її рахує evidence resolver для current checkout.

| Verification | Meaning |
| --- | --- |
| `documented` | Process topology існує, але хоча б один critical step не має resolvable current-checkout evidence. |
| `source-verified` | Кожен critical step має хоча б один mapping, підтверджений існуючим source/use case/command. |
| `runtime-verified` | Кожен critical step має хоча б один mapping до canonical runtime/contract registry. |

`runtime-verified` означає structural runtime verification, а не observed production trace.

## Process Registry contract

Кожен `workflow-v2` має matching JSON definition у `docs/.vitepress/processes/`.

Schema `v4` фіксує:

- stable `id`;
- process Domain;
- business `state` (`as-is` або `to-be`);
- trigger і outcomes;
- actors;
- steps;
- primary `owner` кожного step;
- step `domain`;
- canonical `capability` або explicit `capability_gap`;
- topology через `edges`;
- `critical` transitions;
- runtime/evidence mappings.

Schema v4 зберігає V0.5 invariant: authored `verification` заборонений.

Поточний v4 також вимагає, щоб step залишався всередині process Domain. Cross-domain step потребуватиме окремого explicit contract у наступній версії registry, а не тихого запозичення чужої capability.

Workflow page рендерить три derived projections:

```html
<ProcessDiagram process-id="domain.process-id" />
<ProcessDiagram process-id="domain.process-id" view="ownership" direction="LR" />
<ProcessDiagram process-id="domain.process-id" view="capability" direction="LR" />
```

## Current-checkout evidence catalogue

`generate-runtime-evidence.php` будує machine-readable catalogue напряму з current checkout.

| Evidence type | Authority | Strength / role |
| --- | --- | --- |
| `use_case` | Domain `Application/UseCase/*.php` | source |
| `command` | Domain `Application/DTO/*Command.php` | source |
| `source` | exact repository file + optional symbol | source |
| `event` | explicit Domain event catalogue | runtime |
| `contract` | canonical `cross_domain_contracts` declaration | runtime |
| `capability` | module `contributions.capabilities` | capability authority |

Generated Markdown не є evidence для іншого generated Markdown. Checks і Reference споживають current-checkout authorities.

## Canonical views

### Core business flow

`ProcessDiagram(flow)` відповідає на питання **що за чим відбувається**.

### Ownership view

`ProcessDiagram(ownership)` групує steps за primary responsible actor і відповідає **хто відповідає**.

### Capability view

`ProcessDiagram(capability)` групує ті самі steps за canonical capability. Steps без semantic Domain capability групуються як explicit GAP.

Ця проєкція відповідає **яку здатність Domain реалізує цим кроком і де capability model ще неповна**.

### Interaction sequence і state lifecycle

Sequence/state diagrams не можна чесно вивести лише з generic `steps + edges`. До появи structured interaction/state semantics вони можуть бути supplemental Mermaid, але не canonical derived projections.

## Coverage

Generated [Business Process Registry](../12-reference/business-processes.md) показує окремо:

- Ownership coverage;
- Capability coverage;
- Capability gaps;
- Mapped steps;
- Evidence-verified steps;
- Runtime-backed steps;
- Critical source verification;
- Critical runtime verification.

Це architecture/documentation coverage, а не KPI бізнесу.

Поточний baseline навмисно показує різну зрілість Domain models: Property уже має semantic module capabilities для canonical workflow, тоді як Sales і Diagnostic мають runtime implementation без достатньо точного business-capability vocabulary.

## Modeling rules

1. Core topology редагується в registry definition, а не одночасно в JSON і Mermaid.
2. Кожний step має одного primary `owner`.
3. Кожний step має explicit `domain`.
4. Кожний step має canonical `capability` або explicit `capability_gap`.
5. Capability не вигадується з class name, route чи permission «по сенсу».
6. `state` містить лише business truth: `as-is` або `to-be`.
7. Verification ніколи не авториться вручну.
8. Process має root, terminal і повну reachability.
9. Human steps показуються нарівні з automated steps, якщо вони реальні.
10. Domain decisions відділяються від UI clicks і transport details.
11. Cross-domain transition називає boundary/contract і не створює shared ownership.
12. Exact command/event inventories не дублюються вручну, якщо існує canonical evidence catalogue.
13. Sequence/state projections не генеруються з недостатньої семантики заради красивої картинки.

## Relationship to Architecture Explorer

```mermaid
flowchart LR
    A[Module manifests / capabilities] --> B[Cytoscape Architecture Explorer]
    A --> C[Process Registry capability validation]
    D[Process Registry] --> C
    E[Runtime Evidence Resolver] --> C
    C --> F[ProcessDiagram / Mermaid]
    B --> G[COS Documentation / operational understanding]
    F --> G
```

Cytoscape показує **з чого COS складається і які capabilities належать Domains**. Process Registry + Mermaid показує **як робота рухається через ці capabilities, хто відповідає за steps і наскільки runtime твердження підтверджені current checkout**.

## Change discipline

Зміна business flow, ownership або capability mapping починається з registry definition. Зміна module capabilities або runtime implementation автоматично впливає на checks/coverage. `docs:check` перевіряє topology, ownership, capabilities та evidence; generated Reference оновлює coverage; VitePress показує derived views.

Так документація стає перевірюваною моделлю системи, а не музеєм попередніх намірів.
