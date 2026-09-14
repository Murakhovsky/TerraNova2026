---
title: COS Documentation
description: Канонічна документація Company Operating System.
status: active
updated: 2026-09-14
kind: index
---

# COS Documentation

`main:/docs` є canonical knowledge layer COS, а `main` одночасно є canonical executable branch.

## Source of truth

```text
main code + tests             executable behavior
main module manifests         machine-readable module contract
main generated reference      derived executable facts
main /docs                    workflows, architecture, rationale, navigation
main /docs/11-decisions       long-lived architectural decisions
```

AS-IS твердження повинні підтверджуватися current commit. TARGET не описується як готова поведінка.

## Mental model

```text
Product
→ Workflow
→ Domain
→ Use Case / Command / Event
→ Runtime
→ Policy / Approval
→ Port / Cross-Domain Contract
→ Infrastructure
→ Interface
```

## Рекомендований порядок читання

1. [Що таке COS](00-start/what-is-cos.md)
2. [Mental Model](00-start/mental-model.md)
3. [Current Scope](01-product/current-scope.md)
4. [Domain Map](03-architecture/domain-map.md)
5. [System Map](03-architecture/system-map.md)
6. [Repository Map](00-start/repository-map.md)
7. [Sales Workflow](02-workflows/sales-lead-to-managed-case.md)
8. [Property Workflow](02-workflows/property-submission-to-publication.md)
9. [Diagnostic Workflow](02-workflows/diagnostic-session-to-recommendation.md)
10. [Generated Reference](12-reference/README.md)
11. [Architecture Decisions](11-decisions/README.md)

## Runtime

```text
Business transaction
    ↓
Domain state change
    ↓
Event + Outbox
    ↓
Rule / Agent when needed
    ↓
Policy / Approval
    ↓
Execution through a Port
    ↓
Infrastructure adapter
    ↓
Result Event + Audit + Metrics
```

Не кожна операція проходить увесь ланцюг. Direct use case може завершитися після domain operation + result/event.

## Documentation website

```bash
npm run docs:generate
npm run docs:generate:check
npm run docs:check
npm run docs:dev
npm run docs:build
```

Generators читають той самий current checkout `main`; `.docs-source` і branch sync більше не використовуються.

Детально: [Documentation Build](10-operations/documentation-build.md).
