---
title: Diagnostic Domain Overview
description: Methodology, evidence, evaluation та runtime boundary Diagnostic domain.
status: active
updated: 2026-09-15
kind: domain
---

# Diagnostic Domain Overview

Diagnostic — bounded context для evidence-based business diagnostics.

## Purpose

```text
Methodology → Session → Evidence → Facts / Metrics → Assessment → Findings → Recommendations
```

## Read this domain

<div class="cos-system-map">
  <div class="cos-map-layer">
    <div class="cos-map-title">Diagnostic knowledge path</div>
    <div class="cos-map-grid">
      <a class="cos-map-node" href="./domain-model.html"><strong>Domain Model</strong><span>Methodology, session, evidence, records та traceability.</span></a>
      <a class="cos-map-node" href="./lifecycle-and-evaluation.html"><strong>Lifecycle & Evaluation</strong><span>Publish, execute, score, findings та recommendation loop.</span></a>
      <a class="cos-map-node" href="./contracts-and-code-map.html"><strong>Contracts & Code</strong><span>Repositories, methodology engine, target boundary та implementation.</span></a>
    </div>
  </div>
</div>

## Ownership

Diagnostic володіє methodology packs/versions, sessions, evidence, evaluations, findings, hypotheses, recommendations і closed-loop recommendation outcomes.

## Runtime manifest

```text
id: diagnostic
version: 0.6.1
schema: 0.6.0
kernel: >=0.11.0 <0.12.0
```

AS-IS contributions включають runtime module service `diagnosticDomainModule`, API route contributor, `diagnosticActionOutcomeHandler`, Web navigation та Diagnostic migrations.

## Related workflow and reference

- [Diagnostic Session → Recommendation](../../02-workflows/diagnostic-session-to-recommendation.md)
- [Application Use Cases](../../12-reference/application-use-cases.md)
- [Module & Capabilities](../../12-reference/module-capabilities.md)
- [Event Types](../../12-reference/event-types.md)
