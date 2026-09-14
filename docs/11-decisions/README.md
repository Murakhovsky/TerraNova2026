---
title: Architecture Decisions
description: ADR для рішень, що визначають довгострокову архітектуру COS.
status: active
updated: 2026-09-14
kind: index
---

# Architecture Decisions

| ADR | Decision |
| --- | --- |
| [ADR-0001](ADR-0001-kernel-domain-ownership.md) | Kernel owns mechanisms; Domains own business semantics |
| [ADR-0002](ADR-0002-state-events-outbox.md) | MySQL state + Event + Outbox; COS is not Event Sourcing |
| [ADR-0003](ADR-0003-agents-propose-actions.md) | Agents propose Actions; mutation проходить Policy runtime |
| [ADR-0004](ADR-0004-module-owned-extension-contributions.md) | Modules own extension contributions |
| [ADR-0005](ADR-0005-governed-structured-llm-runtime.md) | Structured LLM access проходить centralized governance runtime |
| [ADR-0006](ADR-0006-deployed-modules-vs-tenant-activation.md) | Deployed module discovery відокремлена від tenant activation |
| [ADR-0007](ADR-0007-documentation-content-and-renderer.md) | `/docs` content відокремлений від generated renderer |
| [ADR-0008](ADR-0008-main-is-canonical-branch.md) | `main` є єдиною canonical code/docs/CI branch |

## Truth hierarchy

```text
Executable code + tests + manifests in main
    ↓
Generated reference
    ↓
Current architecture/product docs
    ↓
Accepted ADR rationale/history
```

Якщо ADR і code розходяться, рішення треба завершити або supersede. Дві несумісні «правди» не є гнучкістю, це просто борг у гарному костюмі.
