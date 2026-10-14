# COS Experience pre-acceptance closure

This workstream is intentionally safe to complete before the human visual review of Golden 8. It must not record, infer, or synthesize a human acceptance decision.

## Allowed before Golden 8 acceptance

- Correct release-candidate accounting so deprecated, superseded, and explicitly exempt contracts remain auditable but do not count as active P0/P1 release candidates.
- Improve contract quality and traceability without marking pages V1_READY.
- Add structured persona, journey, terminology, UX-debt, and design-system-gap registries.
- Make evidence and QA provenance explicit; automated checks may update automated evidence only, never human acceptance.
- Improve dashboards and migration planning so blocked work is visible and prioritized.

## Still requires human review

- Any decision in `resources/experience/golden-decisions.yaml`.
- Marking any of Golden 8 as `V1_READY` based on visual/interaction quality.
- Enabling mass migration that explicitly depends on Golden acceptance.
- Any claim that a page has been visually accepted without a recorded human review.

## Registry contracts

- Personas describe roles and their jobs, not internal implementation teams.
- Journeys describe trigger, user goal, steps, decision points, success state, and failure/recovery paths.
- Terminology establishes canonical user-facing labels and prohibited ambiguous alternatives.
- UX debt items must identify a real user cost, affected routes, severity, evidence, and closure criteria.
- Design-system gaps identify a reusable pattern/component deficiency, affected pages, owner, and acceptance test.

Registries are machine-readable planning inputs. Their existence does not imply the referenced UI is implemented or accepted.
