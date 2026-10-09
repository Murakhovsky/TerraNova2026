---
title: "ADR-0015: COS Federation Architecture"
description: "Федеративна архітектура COS із незалежними доменами та адаптивним інтерфейсом."
status: accepted
updated: 2026-10-08
kind: adr
---

# ADR-0015 — Федеративна архітектура COS

**Decision:** Federated Modular Monolith. **Package:** COS-FEDERATION-FOUNDATION.

## Архітектурна конституція

Centralized Governance. Decentralized Execution. Observable Everything. Independent Evolution.

1. **Kernel / Shared Platform:** identity, tenant, security, policy, approval, command/query/event transports, outbox and audit. Never domain business rules.
2. **Domains:** aggregates, data ownership, business rules, migrations and public application contracts. No direct cross-domain business writes.
3. **Orchestration:** coordinates versioned Goal-to-Outcome through registered capabilities and existing workflows/actions/agents; no domain SQL or duplicate execution engine.
4. **Experience:** Symfony Twig/UX over existing workspace composition and UIAction authorization. Result, Process and Expert are presentation preferences, never roles or permissions.
5. **Architecture Intelligence:** static and runtime evidence remain distinguishable; the graph is not a runtime dispatch broker.
6. **Engineering:** architecture, compatibility, authorization and impact gates run before acceptance. No self-approval for critical changes.

## Контракти, дані та версіонування

Manifest contributions in app/Domains/*/module.php are canonical. Executable capability declarations require named owner, semantic version, schema references, binding, permission, policy, idempotency and tests. A capability declaration cannot authorize execution by itself. Public cross-domain contracts include producer/consumer, failure, version and tenant semantics. Compatible minor versions retain prior behavior; breaking changes require additive V2, staged consumer migration and retirement window.

Physical shared MySQL is allowed while logical write ownership stays domain-specific. Cross-domain reads use published ports/projections; state + event + outbox share a local transaction. No global transactions between domains.

Separate compile-time, deployment, synchronous, asynchronous and data dependency graphs. Reject unsafe synchronous cycles. Track async causation and deduplicate retries.

## Винятки, розгортання та винесення сервісів

Machine-readable policy: federation-governance-policy.json. Waivers require issue/owner/expiry/risk/remediation/approval, and are forbidden for critical tenant/authorization rules. Feature flags cannot bypass critical checks. Extract a Domain to its own service only when measured scale/isolation requirements, stable public contracts, proven tenancy and migration/rollback justify it.

Roll out additively: audit, contracts, graph/CI, execution reliability, goals, experience, E2E. P1 tracing/interaction intelligence follows stable P0.

**Acceptance (updated):** This ADR and Phase 1 code do not imply full completion. **Package A P0** requires a working multi-Domain Goal → Execution → independently verified Outcome, controlled recovery, typed Experience Semantics and tenant/security/rollback tests. **Package B P0** requires one end-to-end Expert → Process → Result presentation built over the accepted Package A data. P1 contextual adaptation comes later.

## Уточнення обсягу релізів: 2026-10-09

Початковий master specification включав `FED-10–13` у Federation P0. Зараз P0 розділено на два **послідовні пакети**, без створення нового Kernel або Domain:

- [Package A: Federation Foundation](../03-architecture/cos-federation-goal-execution-foundation.md) — канонічне виконання Goal, відокремлений Outcome, контроль доступу, відновлення, реальний multi-Domain golden path, **мінімальні** `ExperienceContext`, `ExperienceProfile`, `ExperienceState`, `ExperienceSemantic` і DTO.
- [Package B: Progressive Disclosure](../03-architecture/cos-progressive-disclosure-adaptive-experience.md) — Expert Reference Workspace → Process View → Result View → Adaptive Experience; існуючий UI proof-of-concept не є остаточним критерієм приймання.

Попередню вимогу `three experience modes` із загального Federation DoD перенесено до Package B. Для Package A достатньо сумісних versioned presentation contracts та доступного людського контролю через чинні службові інтерфейси. Усі режими працюють з одними Goal/Run/Outcome, без UI-side authorization.
