# COS Experience UX debt implementation, 2026-10-22

This change builds on **main**, not the closed/unmerged #366 branch.

## Canonical debt mapping

| Previous #366 debt | Canonical main item | Status |
| --- | --- | --- |
| UXD-001: action/state/success contract gaps | UX-005 | OPEN until real P0/P1 actions and outcomes are verified |
| UXD-002: priority inflation | UX-002 | CLOSED on main; no arbitrary reclassification |
| UXD-003: CI QA evidence synchronization | UX-006 | OPEN until CI reconciliation is verified and operational evidence imported |

Priority distribution across all 128 Page Contracts inspected on main before this change: 124 active (excluding four retired/exempt); active P0=48, P1=34, P2=42, P3=0 (P0/P1 = 82/124, about 66%). The policy requires reviewable business evidence for future priority changes; no changes to route priorities are made here.

## UX-005: actionable outcome audit

- Golden Eight Page Contracts now declare explicit, user-observable success conditions.
- `PageContract::toManifest()` exposes goals, primary actions, success conditions, states and declared QA separately.
- `cos:experience:contracts:audit --json` reports missing P0/P1 specific actions, success conditions and required states per route.
- `--strict` fails if structural gaps remain. A complete contract is **not** proof that the corresponding workflow was exercised.
- The Experience dashboard shows actual structural coverage, not an invented acceptance score.

## UX-006: provenance-bound CI reconciliation

- Golden Eight browser QA still writes the original screenshots, Axe JSON reports and summary.
- The CI job imports these via `cos:experience:qa:ingest --source=/tmp/cos-golden-eight --json`.
- Ingest rejects failed summaries, missing/duplicate viewport evidence, invalid PNGs, accessibility violations, and missing or mismatched source SHA, checkout SHA, run ID and attempt. The expected identity is injected from the GitHub Actions runner independently of the artifact.
- The atomic QA projection contains per-page desktop/mobile evidence references, source and checkout SHAs, workflow run/attempt, timestamp and artifact URL.
- CI uploads `page-contract-qa.json` together with the original browser artifacts. The PHP runtime retains imported projections on a dedicated persistent volume.
- The Experience dashboard displays historical CI QA beside each Page Contract and Golden page. It does **not** claim that a historical run matches the current deployment.
- Untrusted offline/operator artifact imports are not allowed to publish CI PASS. CI import does not automatically publish its ephemeral artifact into production. Production synchronization requires a separate authenticated artifact verification and deployment path; until then, production may show NOT_IMPORTED.
- No import changes `human_acceptance`, Golden decisions, quality scores, or `V1_READY`.

## Verification and remaining acceptance

Run the Runtime GitHub workflow, including the Golden Eight browser QA and evidence reconciliation step. Inspect `page-contract-qa.json` in the `golden-eight-evidence` artifact. The production dashboard only displays a projection if one has been safely imported through a verified deployment mechanism. Run `cos:experience:contracts:audit --json` and resolve each listed gap against actual controls and user outcomes. Golden Eight visual/human acceptance remains manual and release remains BLOCKED until its own gates pass.
