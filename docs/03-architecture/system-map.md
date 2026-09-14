---
title: COS System Map
description: Клікабельна карта Product, Kernel, Domains, contracts, interfaces та infrastructure поверхонь COS.
status: active
updated: 2026-09-14
kind: architecture
---

# COS System Map

<div class="cos-branch-contract">
  <span class="cos-badge"><strong>CODE</strong>&nbsp; main</span>
  <span class="cos-badge"><strong>DOCS</strong>&nbsp; main</span>
  <span class="cos-badge"><strong>MODEL</strong>&nbsp; Business → Domain → Runtime → Code</span>
</div>

## Navigation layers

```text
Product / Business Workflows
            ↓
Business Domains
            ↓
Kernel / Runtime
            ↓
Cross-Domain Contracts
            ↓
Ports / Infrastructure / Interfaces
```

### Product

- [Current Scope](../01-product/current-scope.md)
- [Sales Workflow](../02-workflows/sales-lead-to-managed-case.md)
- [Property Workflow](../02-workflows/property-submission-to-publication.md)
- [Diagnostic Workflow](../02-workflows/diagnostic-session-to-recommendation.md)

### Domains

- [Sales](../04-domains/sales/overview.md)
- [Diagnostic](../04-domains/diagnostic/overview.md)
- [Property](../04-domains/property/overview.md)
- [Supporting Domains](../04-domains/supporting-domains.md)

### Runtime and boundaries

- [Kernel](./kernel-overview.md)
- [Execution Lifecycle](../05-runtime/execution-lifecycle.md)
- [Cross-Domain Contracts](./cross-domain-contracts.md)
- [Integration Model](../07-api-integrations/integration-model.md)

### Exact executable facts

- [Generated Reference](../12-reference/README.md)
- [Event Types](../12-reference/event-types.md)
- [Commands](../12-reference/commands.md)
- [Application Use Cases](../12-reference/application-use-cases.md)
- [Module Routes](../12-reference/module-routes.md)

Generated facts синхронізуються з current `main` checkout під час build.
