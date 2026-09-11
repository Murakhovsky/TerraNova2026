---
title: COS Documentation
description: Канонічна документація Company Operating System у гілці COS.
status: active
updated: 2026-09-11
kind: index
---

# COS Documentation

Ця директорія є канонічним входом у документацію **Company Operating System** для гілки `COS`.

Документація будується від бізнес-смислу до коду:

```text
Product
→ Workflow
→ Domain
→ Use Case
→ Runtime
→ Policy / Approval
→ Port
→ Infrastructure
→ Interface
```

## Рекомендований порядок

1. [Що таке COS](00-start/what-is-cos.md)
2. [Mental Model](00-start/mental-model.md)
3. [Repository Map](00-start/repository-map.md)
4. [Current Scope](01-product/current-scope.md)
5. [Domain Map](03-architecture/domain-map.md)
6. [Kernel Overview](03-architecture/kernel-overview.md)
7. [Execution Lifecycle](05-runtime/execution-lifecycle.md)
8. [Sales Lead → Managed Case](02-workflows/sales-lead-to-managed-case.md)
9. [Property Submission → Publication](02-workflows/property-submission-to-publication.md)
10. [Sales Domain](04-domains/sales/overview.md)
11. [Diagnostic Domain](04-domains/diagnostic/overview.md)
12. [Property Domain](04-domains/property/overview.md)
13. [Agent Runtime](06-ai-agents/agent-runtime.md)
14. [Integration Model](07-api-integrations/integration-model.md)
15. [Interface Surfaces](08-ui/interface-surfaces.md)
16. [Adding a Domain](09-development/adding-a-domain.md)

## Головний runtime

```text
Business transaction
    ↓
Domain state change
    ↓
Event + Outbox
    ↓
Durable consumer
    ↓
Rule / Agent
    ↓
ActionProposal
    ↓
Policy
    ├─ DENIED
    ├─ APPROVAL_REQUIRED → Human
    └─ AUTO
         ↓
Queue / Execution
         ↓
Domain port
         ↓
Infrastructure adapter
         ↓
Result Event + Audit + Metrics
```

## Архітектурні рівні

```text
Interfaces        Web / API / Telegram / CLI
Application       use cases and orchestration
Domains           business vocabulary and invariants
Kernel            generic execution mechanisms
Infrastructure    concrete technical adapters
Bootstrap         composition root
```

Kernel володіє механізмами. Domain володіє бізнес-смислом.

## AS-IS і TARGET

- `AS-IS` — підтверджено кодом гілки `COS`.
- `TARGET` — напрямок/правило, яке ще не повністю реалізоване.

Не описуємо TARGET як готову систему. Людство вже винайшло достатньо документації, де майбутній намір подається як production feature.

## Нормативні детальні документи

Зберігаються існуючі:

- `architecture/cos-kernel.md`;
- `architecture/domain-boundaries.md`;
- `architecture/persistence.md`;
- `architecture/diagnostic-domain-model.md`;
- `architecture/frontend-interface.md`;
- `diagnostic/` schemas/assets.

Legacy migration documents залишаються історичним reference, але не визначають поточну COS architecture.

## Source of truth

Код визначає executable reality. `/docs` пояснює ownership, workflows, architecture rules і navigation. Якщо вони розходяться — це defect, який треба виправити, а не нова філософська школа.