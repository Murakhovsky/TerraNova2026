---
title: COS Documentation
description: Канонічна документація Company Operating System у гілці COS.
status: active
updated: 2026-09-12
kind: index
---

# COS Documentation

Ця директорія є канонічним входом у документацію **Company Operating System** для гілки `COS`.

Поточна executable версія Kernel: **`0.10.0`**.

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

## Рекомендований порядок читання

### Зрозуміти COS

1. [Що таке COS](00-start/what-is-cos.md)
2. [Mental Model](00-start/mental-model.md)
3. [Repository Map](00-start/repository-map.md)
4. [Current Scope](01-product/current-scope.md)
5. [COS Glossary](12-reference/glossary.md)

### Зрозуміти архітектуру

6. [Domain Map](03-architecture/domain-map.md)
7. [Kernel Overview](03-architecture/kernel-overview.md)
8. [Extension Runtime](03-architecture/extension-runtime.md)
9. [Execution Lifecycle](05-runtime/execution-lifecycle.md)
10. [Agent Runtime](06-ai-agents/agent-runtime.md)
11. [LLM Governance](06-ai-agents/llm-governance.md)
12. [Integration Model](07-api-integrations/integration-model.md)

### Зрозуміти бізнес-процеси

13. [Sales Lead → Managed Case](02-workflows/sales-lead-to-managed-case.md)
14. [Property Submission → Publication](02-workflows/property-submission-to-publication.md)
15. [Sales Domain](04-domains/sales/overview.md)
16. [Diagnostic Domain](04-domains/diagnostic/overview.md)
17. [Property Domain](04-domains/property/overview.md)

### Розробляти й експлуатувати

18. [Interface Surfaces](08-ui/interface-surfaces.md)
19. [Adding a Domain](09-development/adding-a-domain.md)
20. [Documentation Rules](09-development/documentation-rules.md)
21. [Data and Migrations](10-operations/data-and-migrations.md)
22. [Module Readiness](10-operations/module-readiness.md)
23. [Architecture Decisions](11-decisions/README.md)
24. [Kernel Components Reference](12-reference/kernel-components.md)
25. [Module Capabilities](12-reference/module-capabilities.md)

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

## LLM runtime

```text
Agent / Domain AI use case
    ↓
StructuredLlmRequest
    ↓
Tenant budget
    ↓
Use-case routing policy
    ↓
Provider registry
    ↓
Provider / retryable fallback
    ↓
Usage accounting + metrics
```

LLM inference не отримує mutation authority. Agent proposal усе одно проходить normal Action/Policy lifecycle.

## Module extension runtime

```text
module.php
    ↓
ModuleContributions
    ↓
ModuleExtensionRegistry
    ↓
consumer layer resolves service
    ↓
organization module guard where required
```

Це дозволяє module-owned API/config/UI surfaces без hardcoded списків Domains у shared runtime.

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

Legacy migration documents залишаються historical reference, але не визначають поточну COS architecture.

## Architecture decisions

Нові довгострокові рішення фіксуються в `11-decisions/` як ADR. Current architecture docs пояснюють поточний стан; ADR пояснюють, чому було прийняте конкретне рішення та які альтернативи відхилено.

## Source of truth

Код і tests визначають executable reality. `/docs` пояснює ownership, workflows, architecture rules і navigation. ADR зберігають rationale. Reference, яке можна отримати з manifests/routes/schema, бажано генерувати або автоматично перевіряти.

Детальні правила: [Documentation Rules](09-development/documentation-rules.md).
