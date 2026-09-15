---
title: Business Process Modeling
description: Canonical rules for modeling real COS business processes with Mermaid and explicit truth status.
status: active
updated: 2026-09-15
kind: concept
contract: concept-v1
---

# Business Process Modeling

COS documentation treats a business process as an operational model, not as decorative documentation. Mermaid is the visual renderer; the business meaning remains owned by the relevant Domain and current `main` behavior.

## Truth states

Every canonical workflow page declares `process_state`.

| State | Meaning |
| --- | --- |
| `as-is` | Реальний поточний бізнес-процес, підтверджений current code, tests, manifests або фактичною operational procedure. Не кожен human step мусить бути executable у COS. |
| `to-be` | Цільовий процес, який ще не можна читати як поточну поведінку системи. |
| `runtime-verified` | Критичні transitions мають explicit executable mapping до use cases, commands, events, policies або generated reference поточного `main`. |

`active` у полі `status` означає стан документа. `process_state` означає стан самого бізнес-процесу. Це різні речі.

## Process Registry

Починаючи з DOC V0.11 canonical workflow має structured definition у `docs/.vitepress/processes/*.json`.

```text
Process Definition
  ├── id / domain / truth state
  ├── trigger / actors / outcomes
  ├── steps + edges
  └── runtime mappings
          ↓
check-processes.mjs
          ↓
Generated Business Process Registry
          ↓
Workflow narrative + Mermaid projection
```

Registry не переноситься в Kernel і поки не оголошується runtime source of truth. Це machine-readable documentation contract, який пов'язує business process із поточним executable reference.

`check-processes.mjs` перевіряє:

- unique process IDs і workflow mappings;
- відповідність registry state до `process_state` workflow page;
- unique step IDs і валідні edges;
- існування заявлених use cases, commands та events у generated reference поточного checkout;
- існування source mappings і declared symbols;
- runtime coverage critical steps для `runtime-verified` process.

Generated snapshot: [Business Process Registry](../12-reference/business-processes.md).

## Canonical views

Один процес може мати кілька проєкцій, якщо вони відповідають на різні питання.

### Business flow

Показує, як робота рухається від trigger до business outcome.

```mermaid
flowchart LR
    A[Trigger] --> B[Work]
    B --> C{Decision}
    C -->|Yes| D[Outcome]
    C -->|No| E[Alternative / failure]
```

### Interaction sequence

Показує взаємодію actor, COS, Domain та external system без перенесення business ownership у delivery layer.

```mermaid
sequenceDiagram
    actor User
    participant COS
    participant Domain
    participant External
    User->>COS: intent / action
    COS->>Domain: application operation
    Domain-->>COS: result / event
    COS->>External: optional side effect
```

### State lifecycle

Показує lifecycle однієї domain concept/entity. State diagram не повинен підміняти business flow, якщо процес охоплює кілька сутностей та actors.

```mermaid
stateDiagram-v2
    [*] --> New
    New --> Active
    Active --> Completed
    Active --> Cancelled
    Completed --> [*]
    Cancelled --> [*]
```

## Modeling rules

1. Diagram починається з business trigger і закінчується business outcome або explicit failure/alternative path.
2. Human steps показуються нарівні з automated steps, якщо вони реально є частиною процесу.
3. Domain decisions відділяються від UI clicks і transport details.
4. Cross-domain transition має називати boundary або contract, а не малювати shared ownership.
5. Exact command/event/service inventories не дублюються вручну, якщо для них існує generated reference.
6. `runtime-verified` не використовується лише тому, що частина процесу має код.
7. Mermaid source зберігається поруч із narrative workflow, щоб diff показував зміну process explanation.
8. Structured Process Registry definition зберігає machine-checkable topology та runtime mappings.
9. Якщо registry і narrative розходяться, це documentation defect; жодна з версій не отримує право «ну приблизно ж те саме».

## Mermaid delivery

VitePress перетворює fenced blocks `mermaid` на docs-layer `MermaidDiagram`. Renderer завантажує pinned Mermaid `11.17.2`, використовує `securityLevel: strict` і перемальовує diagram при зміні light/dark theme.

Mermaid є presentation adapter документації. Він не входить у `Kernel\\Visualization` і не стає source of truth для runtime architecture.

Якщо browser runtime не може завантажити renderer, сторінка показує вихідний Mermaid source замість порожнього блоку.

## Relationship to Architecture Explorer

```mermaid
flowchart LR
    A[Architecture metadata] --> B[Cytoscape Architecture Explorer]
    C[Business Process Registry] --> D[Mermaid workflow diagrams]
    B --> E[COS Documentation / operational understanding]
    D --> E
```

Cytoscape відповідає переважно на питання **«з чого COS складається і як компоненти пов'язані?»**. Mermaid відповідає на питання **«як реально рухається робота?»**. Process Registry додає третю властивість: **«на які executable contracts спирається ця модель?»**.

Наступний рівень розвитку — генерувати основну Mermaid topology безпосередньо з Process Registry, залишаючи narrative сторінці explanation, альтернативні sequence/state views та business context.
