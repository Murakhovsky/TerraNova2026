---
title: COS Progressive Disclosure & Adaptive Experience — Package B
description: Послідовний цикл UI/UX поверх замороженого Federation Goal/Execution/Outcome контракту.
status: active
updated: 2026-10-09
kind: architecture
---

# Package B: COS Progressive Disclosure & Adaptive Experience

**Priority:** P0 for reference Expert/Process/Result views; P1 for personalization, usage analytics and AI recommendations. **Depends on:** [Package A](./cos-federation-goal-execution-foundation.md) acceptance of canonical Goal/Plan/Run/Outcome and stable Experience Semantics.

**One shared business model.** Result, Process and Expert never duplicate Goal/Run/Outcome persistence or permission checks. A presentation mode is a view choice, not a product role and not an entitlement.

## B1: Expert Reference Workspace

Choose **one** multi-Domain golden scenario supplied by Package A and render all its operations: Goal/specification version, approved plan DAG, step provenance, canonical Action receipts, approvals, tenant-owned outputs, exception/retry/recovery controls, manual resolutions, and exact evaluation evidence. No hidden irrecoverable actions.

**Acceptance:** an authorized operator can run, inspect, stop/resolve blocked steps and reconcile the same scenario end-to-end without SQL/manual database edits; unauthorized users see no privileged actions.

## B2: Process View

Use the **same Run ID** and state as Expert. Show logical stages, blockers, responsibility, approvals, next action and outcomes. Operational diagnostics are progressive details, not separate facts.

**Acceptance:** a manager can tell what is running, blocked, awaiting approval, done or unverifiable; can perform the next authorized step; switching to Expert does not alter data or rights.

## B3: Result View

First-screen interaction: **«Що хочете отримати?»** → consented Plan → progress → verified outcome. Critical risks, failed steps, missing permissions and required approval must never be hidden by simplification.

**Acceptance:** in under 30 seconds a new user can identify goal, progress, requested decision, business outcome and next action in the reference scenario. No completion badge for missing source evidence.

## B4: Adaptive Experience (P1 behavior)

Contextual reveal, persisted preferences, policy-driven hints, usage analytics and optional AI recommendations. Composition uses `ExperienceSemantic` descriptors (role, priority, recommended mode, trigger, disclosure rule); real permissions always come from Kernel/UIAction authorization. Descriptors alone cannot invoke commands or expose unseen data.

**Acceptance:** relevance tests, keyboard/mobile coverage, tenant isolation of preferences, accessibility, deterministic safe fallback with AI unavailable, no cross-mode divergence.

## Experience Semantics (contract v1)

| Property | Meaning |
| --- | --- |
| `id`, `kind`, `purpose` | Stable item identity and human/business intent |
| `role` | Primary, supporting, contextual, expert |
| `recommended_mode` | Result, process, expert |
| `priority` | Ordering hint, not a privilege |
| `disclosure_rule` | `mode_default`, `on_trigger`, `explicit`, `always` |
| `contextual_triggers` | Fixed list of safe signals (`approval_required`, `blocked`, `failed`, `running`, `result_available`, `risk_attention`, `manual_review`) |
| `capability_id` | Optional capability reference checked separately for tenant availability; never a grant |

Only explicitly resolved, authorized UIActions are eligible to become interactive controls. Domain outputs describe business state, not position or visibility. The Experience Platform composes the view; Domain Policy controls allowable operations.

## UX acceptance matrix

| Case | Result | Process | Expert |
| --- | --- | --- | --- |
| Plan requires human approval | Shows decision and risk | Shows pending step + responsible person | Full immutable Plan, policy, approver, audit |
| Step failed or unknown external outcome | Visible blocker, never false success | Incident/next action | Exact Action/attempt/receipt/reconciliation |
| Verified partial business outcome | Completed count vs target, confidence | Completed/remaining stages | Native evidence IDs and source snapshots |
| Missing permission | No unauthorized mutation control | Explanation, safe read-only state | No bypass through direct URL/API |
| Switch modes | Same Goal/Run/Outcome | Same Goal/Run/Outcome | Same Goal/Run/Outcome |

## Release cadence

**Start only after A golden path accepted.** Build Expert → Process → Result → contextual adaptation. Prototype work already present in Federation is preserved as reusable foundation, not accepted as a finished Adaptive Experience Engine.
