# Historical verification evidence

This directory contains version- and migration-specific checks that were useful while COS moved through Wave/V0.x implementation stages.

They are intentionally **not part of the active V1 verification suite**.

Rules:

- do not add new tests here for current behavior;
- current invariants belong in `tests/architecture`, `tests/unit`, `tests/integration` or `tests/smoke`;
- a historical regression that still matters must be rewritten around the invariant/behavior, not copied back under its old version name;
- Git remains the authoritative history. This directory is a temporary V1 stabilization archive and may be removed once the new suite has proven stable.
