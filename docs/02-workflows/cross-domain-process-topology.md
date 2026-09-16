---
title: Топологія міждоменних процесів
description: Похідне представлення канонічних бізнес-процесів COS, які перетинають межі Domains через задекларовані contracts і capabilities цільових Domains.
status: active
updated: 2026-09-16
kind: concept
contract: concept-v1
---

# Топологія міждоменних процесів

Окремий workflow добре показує, **як рухається одна робота**. Cross-Domain Process Topology (топологія міждоменних процесів) відповідає на інше питання: **де всі канонічні бізнес-процеси COS перетинають межі Domains і через які contracts це дозволено**.

## Поточна topology

<CrossDomainProcessTopology />

Це похідна Mermaid-проєкція з `resources/processes/*.json`. Вона не є окремим source of truth і не зберігає власну копію бізнес-процесів.

Evidence-enriched версія з contract strength, process/step IDs і boundary aggregation генерується в [Cross-Domain Process Topology Reference](../12-reference/cross-domain-process-topology.md).

## Ланцюжок повноважень

```text
Process Registry
step.domain != process.domain
        ↓
Schema v5 contract guard
        ↓
Process Domain --requires contract--> Target Domain
        ↓
Target Domain capability
        ↓
Runtime Evidence Resolver
        ↓
Cross-Domain Process Topology
```

Process Registry визначає **що процес реально переходить в інший Domain**.

Module manifests визначають **чи має Process owner право покладатися на цей synchronous boundary**.

Target capability визначає **яку здатність іншого Domain використовує процес**.

## Що це запобігає

Без такого шару cross-domain dependencies швидко перетворюються на знання типу «Sales десь ходить у Property через якийсь service».

Topology дозволяє машинно побачити:

- які процеси перетинають Domain boundaries;
- з якого Domain у який Domain відбувається hop;
- який `requires` contract його легалізує;
- яка capability належить target Domain;
- чи має contract evidence з поточного checkout;
- скільки process steps використовують одну boundary.

## Правило моделювання

Cross-domain step не означає, що Process owner отримав ownership над чужим станом.

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

Sales може використати Property facts, але Asset, Inventory та Listing не стають Sales entities.

Те саме правило поширюється на майбутні переходи на кшталт:

```text
Sales → Finance
Construction → Procurement
Support → CRM
```

## Зв’язок з іншими представленнями

`ProcessDiagram(domain)` показує Domain transitions **усередині одного workflow**.

Cross-Domain Process Topology агрегує **всі канонічні workflows** в одну карту boundary dependencies.

Cytoscape Architecture Explorer переважно відповідає на питання **з чого складається COS і які architectural contracts існують**.

Ця topology відповідає на питання **які реальні бізнес-процеси ці contracts використовують**.

## Інваріант

> Міждоменна взаємодія повинна бути видимою одночасно на рівні Process, Contract і Capability. Перехід через Domain boundary не створює спільної власності на бізнес-стан.
