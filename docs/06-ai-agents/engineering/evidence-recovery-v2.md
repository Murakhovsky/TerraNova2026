# Engineering Evidence Orchestration & Recovery V2

Implementation scope: Manager-owned read-only repository evidence for Principal Architect.

## Separate output contracts

`NEEDS_REPOSITORY_EVIDENCE` is an **intermediate request**, not an Architecture Decision.
The agent supplies `status` and 1–6 exact `requested_repository_files`, without
provisional `architecture_decision`, `implementation_plan`, `developer_handoff`
or `data_flow`. Only final architecture verdicts need full architecture and
handoff fields. `APPROVED` and `APPROVED_WITH_CONDITIONS` still require the
full independently validated developer handoff.

## Deterministic evidence budget

- Up to 4 evidence rounds and 6 paths per request, with 12 additional files total.
- Initial context selection gives domain/feature context map higher precedence than
  revision-diff files, after canonical architecture standards.
- Budget is passed into the Architect context with exact known paths, rounds and
  file slots remaining. Each request is checked path-by-path against the
  existing fail-closed read-only policy. One rejected file should not suppress
  other valid files from the same request.
- Rejected filenames are hashed in event logs; no secrets or unauthorized
  files are fetched. Reads stay pinned to the current validated repository revision.
- Out-of-budget or entirely disallowed evidence requests are handled as
  typed Manager technical outcomes instead of creating human approval requests.
  They do not automatically increase the evidence budget.

## Recovery semantics

`EVIDENCE_BUDGET_EXHAUSTED` and `EVIDENCE_REQUEST_REJECTED` are
Manager-owned technical outcomes, never authorization or architecture approval.
The workflow is moved to `BLOCKED` with a system transition and a journalled
recovery hint. Existing Architecture Decision and Developer Handoff records
are not overwritten by a provisional technical outcome. After the underlying
scoped evidence issue is addressed, an operator may explicitly resume. Do not
automatically rerun an unchanged expensive Architect request.

## Events & UI

- `architect.evidence_request_evaluated`: selected file paths, rejected
  path hashes, budget and selection reason.
- `architect.repository_evidence_collected`: pinned revision and actual bytes.
- `architect.evidence_collection_stopped`: typed reason, exhausted budget,
  and safe recovery instructions.
- Engineering terminal displays the latest evidence stop with the file/round
  counters. Completed provider calls are not Architecture Gate approvals.

## Acceptance

Tests: `engineering_architect_evidence_planner.php`,
`engineering_agent_output_validation.php`, `engineering_agent_contracts.php`,
`engineering_architect_stage.php` and `engineering_feature_ui.php`.
For rollout, compare complete Actor-to-Developer progress and combined LLM usage
on a controlled feature. Do not use the already-stalled production feature
for uncontrolled retries.
