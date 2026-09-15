---
title: COS Product Capabilities
description: Capability model of COS from business workflows to governed automation and integration.
status: active
updated: 2026-09-15
kind: product
---

# COS Product Capabilities

Capability тут означає **що система дозволяє бізнесу робити**, а не назву PHP service.

## Capability layers

<div class="cos-system-map">
  <div class="cos-map-layer">
    <div class="cos-map-title">Business capabilities</div>
    <div class="cos-map-grid">
      <a class="cos-map-node" href="../04-domains/sales/overview.html"><strong>Sales Operations</strong><span>Demand intake, cases/deals, pipeline, activities, follow-up and outcomes.</span></a>
      <a class="cos-map-node" href="../04-domains/property/overview.html"><strong>Property Operations</strong><span>Canonical assets, inventory, listings, publication and external network.</span></a>
      <a class="cos-map-node" href="../04-domains/diagnostic/overview.html"><strong>Business Diagnostics</strong><span>Methodology, evidence, assessment, findings and recommendations.</span></a>
    </div>
  </div>

  <div class="cos-map-layer">
    <div class="cos-map-title">Operating-system capabilities</div>
    <div class="cos-map-grid">
      <a class="cos-map-node is-kernel" href="../05-runtime/execution-lifecycle.html"><strong>Governed Execution</strong><span>Commands/actions, policies, approval, queue and results.</span></a>
      <a class="cos-map-node is-kernel" href="../05-runtime/events-and-outbox.html"><strong>Event-driven Work</strong><span>Business facts, durable delivery and automation triggers.</span></a>
      <a class="cos-map-node is-kernel" href="../05-runtime/audit-and-diagnostics.html"><strong>Audit & Observability</strong><span>Trace what happened, why, where and with what result.</span></a>
      <a class="cos-map-node" href="../06-ai-agents/agent-runtime.html"><strong>Agent-assisted Decisions</strong><span>Structured proposals on Domain-owned context under explicit authority.</span></a>
    </div>
  </div>

  <div class="cos-map-layer">
    <div class="cos-map-title">Platform capabilities</div>
    <div class="cos-map-grid">
      <a class="cos-map-node" href="../03-architecture/extension-runtime.html"><strong>Modular Runtime</strong><span>Installable contributions and tenant activation without Kernel business coupling.</span></a>
      <a class="cos-map-node" href="../07-api-integrations/integration-model.html"><strong>Integrations</strong><span>External systems behind canonical ports/adapters and vocabulary translation.</span></a>
      <a class="cos-map-node" href="../12-reference/permissions-capabilities.html"><strong>Authority & Permissions</strong><span>Capabilities and permission boundaries for execution.</span></a>
      <a class="cos-map-node" href="../08-ui/interface-surfaces.html"><strong>Operational UI</strong><span>Human workspaces that expose Domain operations without owning the rules.</span></a>
    </div>
  </div>
</div>

## AS-IS vs product direction

Не кожна capability має однакову maturity. [Current Scope](./current-scope.md) є authoritative human-readable AS-IS snapshot; ця сторінка пояснює product model.

## Capability composition

Один business workflow може використовувати кілька capabilities:

```text
Sales Event
→ Agent-assisted Decision
→ Policy / Permission
→ Approval (optional)
→ Sales Use Case
→ External CRM Adapter
→ Result Event
→ Audit
```

Саме composition, а не дублювання функцій у кожному Domain, робить COS операційною системою, а не колекцією окремих застосунків.
