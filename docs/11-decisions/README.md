---
title: Архітектурні рішення
description: ADR для рішень, що визначають довгострокову архітектуру COS.
status: active
updated: 2026-09-21
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
| [ADR-0009](ADR-0009-web-experience-platform.md) | Symfony Web & Experience Platform є канонічним UI runtime COS |
| [ADR-0010](ADR-0010-web-ui-foundation-freeze.md) | Web/UI foundation заморожена; visual system розвивається поверх semantic tokens і canonical components |
| [ADR-0011](ADR-0011-web-platform-v1-freeze.md) | Web Platform v1 contract surface заморожена після Sales cutover; фундаментальні зміни потребують ADR |

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
