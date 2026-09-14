---
layout: home
title: COS Documentation
description: Канонічна документація Company Operating System.
hero:
  name: Company Operating System
  text: Documentation
  tagline: Від бізнес-процесу до Domain, Runtime, Agent, Policy, Integration і коду.
  actions:
    - theme: brand
      text: Почати з Mental Model
      link: /00-start/mental-model
    - theme: alt
      text: Поточний Scope
      link: /01-product/current-scope
features:
  - title: Understand COS
    details: Product, mental model, workflows та bounded contexts без необхідності читати сотні PHP-файлів.
    link: /00-start/what-is-cos
  - title: Build with COS
    details: Kernel, extension runtime, Agents, integrations, development rules та operational lifecycle.
    link: /03-architecture/kernel-overview
  - title: Architecture Decisions
    details: Причини фундаментальних рішень, альтернативи та наслідки зафіксовані окремими ADR.
    link: /11-decisions/README
---

## Канонічний шлях

```text
Business
→ Workflow
→ Domain
→ Use Case / Event
→ Kernel Runtime
→ Policy / Approval / Queue
→ Domain Port
→ Infrastructure Adapter
→ Result / Audit
```

Документація читається зверху вниз. Якщо потрібно знайти конкретний class або table, починайте з бізнес-capability, а не з випадкового `Service.php`. Система вже достатньо велика, щоб археологічний метод перестав бути романтичним.
