---
title: Diagnostic Domain Overview
description: Methodology, evidence, evaluation та runtime boundary Diagnostic domain.
status: active
updated: 2026-09-14
kind: domain
---

# Diagnostic Domain Overview

Diagnostic — bounded context для evidence-based business diagnostics.

## Purpose

```text
Methodology → Session → Evidence → Facts / Metrics → Assessment → Findings → Recommendations
```

## Ownership

Diagnostic володіє methodology packs/versions, sessions, evidence, evaluations, findings, hypotheses, recommendations і closed-loop recommendation outcomes.

## Runtime manifest

```text
id: diagnostic
version: 0.6.1
schema: 0.6.0
kernel: >=0.11.0 <0.12.0
```

AS-IS contributions:

- runtime module service `diagnosticDomainModule`;
- API route contributor `diagnosticRouteContributor`;
- event consumer `diagnosticActionOutcomeHandler`;
- Web navigation contribution;
- migration `20260914_000049_diagnostic_runtime_v060.sql`.

Diagnostic runtime persistence і API boundary уже executable у current `main`.
