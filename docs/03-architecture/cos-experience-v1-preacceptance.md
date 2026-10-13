---
title: COS Experience V1 — pre-acceptance closure
description: Machine-governed work allowed before Golden 8 human acceptance.
status: active
updated: 2026-10-13
kind: architecture
---

# Scope

Golden 8 human acceptance remains the trust boundary for mass production UI migration and `V1_READY` promotion.

Before that decision, COS may and should complete all non-human preparation:

- route and Page Contract inventory;
- Persona Registry;
- User Journey Registry;
- canonical terminology;
- UX Debt and Design System Gap registries;
- automated browser/functional/responsive/accessibility evidence;
- deterministic migration planning;
- release-scope correctness;
- agent context preparation.

## Hard boundary

Pre-acceptance automation MUST NOT:

- write `ACCEPT` into the Golden decision ledger;
- set `human_acceptance=true`;
- promote a Golden page to `V1_READY`;
- start EX-005 production UI mass migration while the Golden gate is blocked;
- reinterpret automated screenshots as human product acceptance.

## Release scope

`DEPRECATED`, `SUPERSEDED` and `EXEMPT` contracts are governance history, not active release candidates. They remain visible in the Registry but are excluded from P0/P1 readiness denominators.

## Product governance

`cos:experience:product-governance --strict` validates the structural Product UX layer:

- every Page Contract persona exists;
- every Journey persona exists;
- every Journey page exists;
- terminology identifiers are unique;
- governance manifests are present.

Generic auto-inventory copy, priority inflation and open UX debt are reported explicitly as product debt. They are not silently converted into acceptance.

## Golden evidence

Automated browser QA may update automated QA evidence for a Golden page, but quality scores and human acceptance remain separate. This allows CI evidence to be truthful without pretending that a human has approved the product.
