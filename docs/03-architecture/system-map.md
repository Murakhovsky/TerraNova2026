---
title: COS System Map
description: Drill-down карта Product, Workflows, Domains, Runtime, contracts, interfaces та executable reference COS.
status: active
updated: 2026-09-15
kind: architecture
---

# COS System Map

<div class="cos-branch-contract">
  <span class="cos-badge"><strong>CODE</strong>&nbsp; main</span>
  <span class="cos-badge"><strong>DOCS</strong>&nbsp; main</span>
  <span class="cos-badge"><strong>MODEL</strong>&nbsp; Business → Workflow → Domain → Runtime → Code</span>
</div>

System Map читається зверху вниз. Починайте з бізнес-питання і провалюйтеся до exact executable facts лише тоді, коли вони потрібні.

<div class="cos-system-map">
  <div class="cos-map-layer">
    <div class="cos-map-title">1 · Business Workflows</div>
    <div class="cos-map-grid">
      <a class="cos-map-node" href="../02-workflows/sales-lead-to-managed-case.html"><strong>Sales</strong><span>Lead → managed case → pipeline → follow-up → outcome.</span></a>
      <a class="cos-map-node" href="../02-workflows/property-submission-to-publication.html"><strong>Property</strong><span>Submission → canonical asset → inventory → listing → publication.</span></a>
      <a class="cos-map-node" href="../02-workflows/diagnostic-session-to-recommendation.html"><strong>Diagnostic</strong><span>Methodology → evidence → assessment → recommendation.</span></a>
    </div>
  </div>

  <div class="cos-map-flow">↓</div>

  <div class="cos-map-layer">
    <div class="cos-map-title">2 · Business Domains</div>
    <div class="cos-map-grid">
      <a class="cos-map-node" href="../04-domains/sales/overview.html"><strong>Sales</strong><span>Demand lifecycle, pipeline, activities, automation and CRM translation.</span></a>
      <a class="cos-map-node" href="../04-domains/property/overview.html"><strong>Property</strong><span>Asset registry, Inventory, Listing/Publication, network and intelligence.</span></a>
      <a class="cos-map-node" href="../04-domains/diagnostic/overview.html"><strong>Diagnostic</strong><span>Methodology, evidence, deterministic evaluation and recommendations.</span></a>
      <a class="cos-map-node" href="../04-domains/supporting-domains.html"><strong>Supporting Domains</strong><span>Other bounded contexts and current ownership boundaries.</span></a>
    </div>
  </div>

  <div class="cos-map-flow">↓</div>

  <div class="cos-map-layer">
    <div class="cos-map-title">3 · Kernel / Runtime</div>
    <div class="cos-map-grid">
      <a class="cos-map-node is-kernel" href="./kernel-overview.html"><strong>Kernel</strong><span>Generic mechanisms without business vocabulary.</span></a>
      <a class="cos-map-node is-kernel" href="../05-runtime/execution-lifecycle.html"><strong>Execution</strong><span>Intent/Fact → decision → authority → execution → result.</span></a>
      <a class="cos-map-node is-kernel" href="../05-runtime/events-and-outbox.html"><strong>Events & Outbox</strong><span>Facts, durable delivery and transaction boundary.</span></a>
      <a class="cos-map-node is-kernel" href="../05-runtime/policies-and-approvals.html"><strong>Policy & Approval</strong><span>AUTO, approval-required and denied mutations.</span></a>
      <a class="cos-map-node is-kernel" href="../06-ai-agents/agent-runtime.html"><strong>Agent Runtime</strong><span>Context → proposal → policy; never silent direct mutation.</span></a>
    </div>
  </div>

  <div class="cos-map-flow">↓</div>

  <div class="cos-map-layer">
    <div class="cos-map-title">4 · Contracts / Interfaces / Infrastructure</div>
    <div class="cos-map-grid">
      <a class="cos-map-node" href="./cross-domain-contracts.html"><strong>Cross-Domain Contracts</strong><span>Ownership-safe communication between bounded contexts.</span></a>
      <a class="cos-map-node" href="../07-api-integrations/integration-model.html"><strong>Integrations</strong><span>Provider adapters, APIs and external boundaries.</span></a>
      <a class="cos-map-node" href="../08-ui/interface-surfaces.html"><strong>UI Surfaces</strong><span>Portal, workspace and operational interfaces.</span></a>
      <a class="cos-map-node" href="../00-start/repository-map.html"><strong>Repository Map</strong><span>Where architecture lands in the codebase.</span></a>
    </div>
  </div>

  <div class="cos-map-flow">↓</div>

  <div class="cos-map-layer">
    <div class="cos-map-title">5 · Executable Reference</div>
    <div class="cos-map-grid">
      <a class="cos-map-node" href="../12-reference/module-capabilities.html"><strong>Modules & Capabilities</strong><span>Exact manifests and capabilities from current checkout.</span></a>
      <a class="cos-map-node" href="../12-reference/application-use-cases.html"><strong>Use Cases</strong><span>Executable application entry points.</span></a>
      <a class="cos-map-node" href="../12-reference/event-types.html"><strong>Events</strong><span>Canonical event inventory.</span></a>
      <a class="cos-map-node" href="../12-reference/commands.html"><strong>Commands</strong><span>Canonical command inventory.</span></a>
      <a class="cos-map-node" href="../12-reference/module-routes.html"><strong>Routes</strong><span>Module-owned route contributions.</span></a>
    </div>
  </div>
</div>

## Canonical drill-down routes

### Sales

[Workflow](../02-workflows/sales-lead-to-managed-case.md) → [Overview](../04-domains/sales/overview.md) → [Domain Model](../04-domains/sales/domain-model.md) → [Lifecycle & Automation](../04-domains/sales/lifecycle-and-automation.md) → [Contracts & Code](../04-domains/sales/contracts-and-code-map.md) → [Executable Reference](../12-reference/application-use-cases.md)

### Property

[Workflow](../02-workflows/property-submission-to-publication.md) → [Overview](../04-domains/property/overview.md) → [Domain Model](../04-domains/property/domain-model.md) → [Lifecycle & Runtime](../04-domains/property/lifecycle-and-runtime.md) → [Contracts & Code](../04-domains/property/contracts-and-code-map.md) → [Executable Reference](../12-reference/module-capabilities.md)

### Diagnostic

[Workflow](../02-workflows/diagnostic-session-to-recommendation.md) → [Overview](../04-domains/diagnostic/overview.md) → [Domain Model](../04-domains/diagnostic/domain-model.md) → [Lifecycle & Evaluation](../04-domains/diagnostic/lifecycle-and-evaluation.md) → [Contracts & Code](../04-domains/diagnostic/contracts-and-code-map.md) → [Executable Reference](../12-reference/application-use-cases.md)

Generated facts синхронізуються з current `main` checkout під час build.
