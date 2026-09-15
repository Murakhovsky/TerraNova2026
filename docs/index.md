---
layout: home
title: COS Documentation
description: Канонічна WEB-документація Company Operating System.
hero:
  name: Company Operating System
  text: Documentation
  tagline: Від бізнес-процесу до Domain, Runtime, Contract, Agent і коду без археології по випадкових Service.php.
  actions:
    - theme: brand
      text: Зрозуміти COS за 10 хв
      link: /00-start/what-is-cos
    - theme: alt
      text: Відкрити System Map
      link: /03-architecture/system-map
features:
  - title: Understand COS
    details: Product model, Mental Model, current scope, workflows та bounded contexts.
    link: /00-start/what-is-cos
  - title: Business Workflows
    details: Sales, Property і Diagnostic від business goal до decisions, failures та code map.
    link: /02-workflows/sales-lead-to-managed-case
  - title: Architecture
    details: Kernel, Domains, cross-domain contracts, runtime, persistence та dependency direction.
    link: /03-architecture/domain-map
  - title: Build with COS
    details: Development rules, generated reference, module lifecycle, testing та operations.
    link: /09-development/adding-a-domain
  - title: Executable Reference
    details: Versions, modules, capabilities, events, commands, routes та use cases, згенеровані з поточного main.
    link: /12-reference/README
---

<div class="cos-branch-contract">
  <span class="cos-badge"><strong>CODE</strong>&nbsp; main</span>
  <span class="cos-badge"><strong>DOCS</strong>&nbsp; main</span>
  <span class="cos-badge"><strong>AUTHORITY</strong>&nbsp; current commit</span>
</div>

## Один commit — одна executable reality

```text
main
├─ code + tests
├─ manifests + migrations
├─ narrative docs
├─ documentation generators
└─ CI / deployment metadata
```

AS-IS documentation перевіряється проти того самого checkout, з якого збирається COS. Cross-branch sync більше немає.

## Канонічний шлях від бізнесу до реалізації

```text
Business problem
→ Workflow
→ Owning Domain
→ Use Case / Command / Event
→ Cross-Domain Contract when required
→ Kernel mechanism
→ Port
→ Infrastructure Adapter
→ Interface / Result / Audit
```

## Поточний baseline

| Component | Version | Role |
| --- | --- | --- |
| Kernel | `0.11.8` | generic execution platform |
| Sales | `0.8.6` | reference runtime Domain |
| Diagnostic | `0.6.1` | executable diagnostic runtime |
| Property | `0.12.0` | canonical asset runtime, inventory/listing writes, compatibility projection, intelligence and network interoperability |

Exact module facts генеруються з `main/app/Domains/*/module.php` у [Generated Reference](./12-reference/README.md).
