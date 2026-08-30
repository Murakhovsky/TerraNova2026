# Diagnostic Domain

`Diagnostic` is the generic COS bounded context for running evidence-based business diagnostics. It owns methodology packs, immutable pack versions, diagnostic sessions and the traceability chain from evidence to facts, metrics, assessments, findings, hypotheses and recommendations.

It does not own Sales, Finance, HR or another target domain model. A pack identifies its target with a neutral `DiagnosticTarget`; target-specific vocabulary lives in the pack definition or in the target bounded context.

## Core lifecycle

```text
draft pack -> publish immutable version -> start session
-> capture evidence -> record traceable diagnostic records
-> complete session
```

Published pack versions cannot be edited. `DiagnosticPack::revise()` creates the next draft version while an existing session remains pinned to the exact pack id and version with which it started.

Every derived record declares its evidence and/or upstream record references. The `DiagnosticSession` aggregate rejects dangling references and exposes a complete traceability map.

## Layout

```text
Diagnostic/
|-- Model/                  aggregates, entities, value objects and invariants
|   `-- Policy/             publication and completion policies
|-- Methodology/            loader, validation, deterministic engines and results
|-- Application/
|   |-- Contract/           pack and session repository ports
|   |-- DTO/                immutable commands
|   `-- UseCase/            transactional lifecycle orchestration
|-- Automation/Event/       Diagnostic-owned domain events
`-- Infrastructure/         tenant-scoped MySQL repositories
```

`DiagnosticPack` is the single lifecycle aggregate around a complete `MethodologyPack`. Publication validates the full methodology and stores a canonical SHA-256 content hash. Sessions pin pack id/version; MySQL persistence uses tenant scope and optimistic locking so a published methodology remains reproducible and concurrent session writes cannot silently overwrite each other.

Target-specific packs remain separate data. The executable Sales fixture demonstrates the contract without introducing a dependency from `Diagnostic` to the `Sales` model.

## Normative design

The formal domain semantics, state transitions, deterministic/AI boundary and compiler contract are defined in [`docs/architecture/diagnostic-domain-model.md`](../../../docs/architecture/diagnostic-domain-model.md). The machine-readable methodology contract is [`docs/diagnostic/diagnostic-pack.schema.json`](../../../docs/diagnostic/diagnostic-pack.schema.json).

The current PHP model is an initial skeleton. Where it is less expressive than the normative design, new implementation must converge on the normative semantics rather than extending the generic `DiagnosticRecord` shape ad hoc.

## Deterministic methodology engine

`Methodology/` turns a JSON/YAML methodology plus structured facts and metrics into deterministic assessments. Its loader performs no business interpretation; `PackValidator` rejects broken references, invalid ranges and circular dependencies before evaluation. `MethodologyEngine` then orchestrates coverage, confidence, rules, scoring and the declared dependency graph. `DiagnosticRunner` accepts either normalized `DiagnosticInput` or an existing `DiagnosticSession`; the session adapter consumes only Fact/Metric values and their evidence metadata.

Scores are deliberately suppressed when a criterion does not meet its `minimum_coverage`. Rule conditions read only `fact.*`, `metric.*` and `assessment.*` values; unstructured text and LLM output are outside this engine.

The executable example is `tests/fixtures/diagnostic/sales-methodology.json`; its end-to-end verification is `tests/smoke/diagnostic_methodology.php`.

## Phase 1 execution path

```text
draft + validate + publish methodology
-> start version-pinned session
-> capture evidence-backed facts and metrics
-> EvaluateDiagnosticSession
-> persist assessments and findings with evidence/upstream references
-> add traceable hypotheses and recommendations
-> complete and freeze session
```

The migration is `20260830_000020_diagnostic_domain.sql`. The real-MySQL verification rolls the complete flow back after checking tenant isolation, canonical hashes, optimistic locking, result rehydration and atomic Event/Outbox persistence.

The executable Phase 2 file contract is documented by `docs/diagnostic/methodology-pack-phase2.schema.json`. JSON and YAML sources compile to the same immutable model and canonical content hash. Required criterion inputs may carry individual weights; both coverage and confidence thresholds gate findings and every score level. A missing criterion score blocks its section and pack score instead of being silently omitted.
