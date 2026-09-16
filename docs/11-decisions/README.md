---
title: Архітектурні рішення
description: ADR для рішень, що визначають довгострокову архітектуру COS.
status: active
updated: 2026-09-16
kind: index
---

# Архітектурні рішення

| ADR | Рішення |
| --- | --- |
| [ADR-0001](ADR-0001-kernel-domain-ownership.md) | Kernel володіє механізмами, Domains володіють бізнес-семантикою |
| [ADR-0002](ADR-0002-state-events-outbox.md) | MySQL state + Event + Outbox; COS не використовує Event Sourcing як базову persistence model |
| [ADR-0003](ADR-0003-agents-propose-actions.md) | Agents пропонують Actions; mutation проходить Policy/Approval runtime |
| [ADR-0004](ADR-0004-module-owned-extension-contributions.md) | Modules володіють extension contributions |
| [ADR-0005](ADR-0005-governed-structured-llm-runtime.md) | Structured LLM access проходить централізований governance runtime |
| [ADR-0006](ADR-0006-deployed-modules-vs-tenant-activation.md) | Deployed module discovery відокремлена від tenant activation і readiness |
| [ADR-0007](ADR-0007-documentation-content-and-renderer.md) | `/docs` content відокремлений від static renderer; generated reference має власний sync contract |
| [ADR-0008](ADR-0008-main-is-canonical-branch.md) | `main` є єдиною canonical code/docs/CI branch |

## Ієрархія джерел істини

```text
Executable code + tests + manifests + Process Registry у main
    ↓
Generated reference
    ↓
Current architecture/product/process docs
    ↓
Accepted ADR rationale/history
```

ADR пояснює **чому** система має певну архітектурну форму. Він не повинен дублювати exact inventories, які вже можна згенерувати з коду.

Якщо ADR і executable code розходяться, рішення треба або завершити, або явно supersede/оновити. Дві несумісні «канонічні правди» не є гнучкістю, це просто борг у гарному костюмі.
