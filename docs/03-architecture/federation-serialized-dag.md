# Federation Serialized DAG Execution (P0 incremental slice)

This slice extends the existing Goal/Plan/Run/Action lifecycle. It does not
introduce a second worker, Action runtime, permission model or orchestration engine.

## Approved Plan dependencies

- Each normalized step contains `depends_on: string[]` in the immutable approved
  Plan JSON. IDs are namespaced-identifier-shaped and unique.
- An omitted `depends_on` retains the original predecessor in *approved Plan
  array order* for compatibility with earlier linear Plans. Use an explicit
  `depends_on: []` to create an independent root.
- Edges may refer to any known step and are rejected when self-referential,
  duplicated or cyclic. Plans with more than 100 steps are rejected.
- Reordering SQL result rows never changes execution precedence.
- Plans are integrity-protected through the existing independent human-approved
  plan hash. Dependency modifications require a new Plan approval.

## Serialized execution guarantee

The cursor determines runnable steps using validated dependencies and persisted
step states. Two independent branches are **not** dispatched in parallel:
at most one external Action may be claimed at any time. A claimed or ambiguous
Action blocks the next dispatch. Completed dependencies are re-attested through
the existing canonical Action receipt reconciler before further submission.

Only external Action steps supported by canonical Policy/Approval admission are
executable through this adapter. Failed or uncertain Action receipts require
manual reconciliation. No autonomous retry or new financial/live trading
authority is provided. Goal outcome evidence is separate from step completion.

## Tests and remaining work

`bash bin/verify unit` runs:
- `tests/unit/federation_step_cursor.php` for branches, join, cycle, drift,
  skipped prerequisites and concurrent-claim rejection.
- `tests/unit/federation_goal_outcome.php` for normalization and compatibility.

Production P0 acceptance still requires live Domain capability coverage,
transactional recovery/outbox, worker restart scenarios, outcome observations
from authoritative Domain state, multi-domain E2E and rollback rehearsal.
