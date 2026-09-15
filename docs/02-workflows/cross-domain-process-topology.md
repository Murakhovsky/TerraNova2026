---
title: Cross-Domain Process Topology
description: Derived view of canonical COS business processes that cross Domain boundaries through declared contracts and target capabilities.
status: active
updated: 2026-09-15
kind: concept
contract: concept-v1
---

# Cross-Domain Process Topology

Окремий workflow добре показує, **як рухається одна робота**. Cross-Domain Process Topology відповідає на інше питання: **де всі canonical business processes COS перетинають межі Domains і через які контракти це дозволено**.

## Live topology

<CrossDomainProcessTopology />

Це derived Mermaid projection з `docs/.vitepress/processes/*.json`. Вона не є окремим source of truth і не зберігає власну копію бізнес-процесів.

Evidence-enriched версія з contract strength, process/step IDs і boundary aggregation генерується у [Cross-Domain Process Topology Reference](../12-reference/cross-domain-process-topology.md).

## Authority chain

```text
Process Registry
step.domain != process.domain
        ↓
Schema v5 contract guard
        ↓
process Domain --requires contract--> target Domain
        ↓
target Domain capability
        ↓
Runtime Evidence Resolver
        ↓
Cross-Domain Process Topology
```

Process Registry визначає **що процес реально переходить у чужий Domain**. Module manifests визначають **чи має process owner право покладатися на такий synchronous boundary**. Target capability визначає **яку здатність чужого Domain використовує процес**.

## What this prevents

Без такого шару cross-domain dependencies дуже швидко стають знанням типу «десь Sales ходить у Property, здається через якийсь service». Для COS це неприйнятно, бо shared business ownership починає рости швидше за бур'ян після дощу.

Topology дозволяє машинно побачити:

- які процеси перетинають Domain boundaries;
- з якого Domain і в який Domain відбувається hop;
- який `requires` contract його легалізує;
- яка capability належить target Domain;
- чи має contract current-checkout runtime evidence;
- скільки process steps використовують один boundary.

## Modeling rule

Cross-domain step не означає, що process owner отримав ownership над чужим state.

```text
Sales process
    ↓ requires
PropertyReferencePort
    ↓
Property capability
    ↓
Property facts
    ↓
Sales-owned relationship/result
```

Sales може використати Property facts, але Asset / Inventory / Listing не стають Sales entities. Те саме правило поширюється на майбутні `Sales → Finance`, `Construction → Procurement`, `Support → CRM` та інші переходи.

## Relationship to other views

`ProcessDiagram(domain)` показує Domain переходи **всередині одного workflow**. Cross-Domain Process Topology агрегує **всі canonical workflows** в одну карту boundary dependencies.

Cytoscape Architecture Explorer відповідає переважно на питання **з чого COS складається і які architectural contracts існують**. Ця topology відповідає **які реальні business processes ці contracts використовують**.
