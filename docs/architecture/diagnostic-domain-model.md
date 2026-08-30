# COS Diagnostics: domain model and methodology contract

Status: **baseline v1.0**  
Scope: generic Diagnostic bounded context; Sales is a methodology pack, not a dependency of this domain.

## 1. Architectural boundary

`Diagnostic` owns versioned methodologies, diagnostic sessions, evidence provenance, derived knowledge, assessment state and the traceability graph. It does not own CRM, Sales, Finance, HR or their operational records.

```text
Target domain adapters                         Diagnostic
CRM / files / interview / API  -> Observation -> Evidence
                                                    |
                                                    v
Fact -> Metric -> Assessment -> Finding -> Hypothesis
                                                    |
                                                    v
                                             Recommendation
                                                    |
                                                    v
                                       target domain Decision/Action
                                                    |
                                                    v
Result -> Measurement -> new Evidence / FactRevision
```

The deterministic engine owns metrics, rules, scores, coverage, confidence composition, dependencies, eligibility and state transitions. An LLM may extract or normalize facts, formulate questions, interpret contradictions, propose hypotheses and customize narrative. LLM output is always a proposal with provenance; it never changes a deterministic assessment, confirms a root cause or executes a recommendation directly.

## 2. Aggregate boundaries

### DiagnosticPack

An immutable, publishable methodology revision.

Identity is `(pack_id, version)`, where `version` is a positive monotonically increasing integer. `schema_version` identifies the file contract independently of the methodology version.

```text
DRAFT -> PUBLISHED -> RETIRED
              |
              +---- revise() -> new DRAFT version
```

Only a `DRAFT` pack may change. Publishing requires successful compilation. Existing sessions remain pinned to their exact published version.

### DiagnosticSession

The transactional aggregate root for one diagnostic run.

```text
PLANNED -> IN_PROGRESS -> COMPLETED
    |           |
    +-----------+------> CANCELLED
```

- Only `IN_PROGRESS` accepts evidence, fact revisions and diagnostic records.
- Completion requires every required criterion to have a conclusive status or an explicit `NOT_APPLICABLE`/`INSUFFICIENT_DATA` result with rationale.
- A completed or cancelled session is immutable.
- Records are append-only. Correction creates a superseding record; it never rewrites provenance.

### DiagnosticState

`DiagnosticState` is the deterministic, rebuildable state owned by a session, not a second source of truth. It is computed from the pinned compiled pack plus append-only session records.

```text
DiagnosticState
|- known_facts
|- missing_facts
|- evidence_gaps
|- contradictions
|- metrics
|- assessments
|- findings
|- hypotheses
|- recommendations
|- unresolved_questions
|- coverage
`- confidence
```

Every state snapshot has `session_id`, `pack_id`, `pack_version`, `revision`, `computed_at` and the IDs of all inputs used. The same ordered inputs and compiled pack must produce the same state.

## 3. Canonical entities and semantics

| Entity | Meaning | Mutability / identity |
|---|---|---|
| `Observation` | A captured statement, event or raw measurement before it is accepted as evidence | Immutable event |
| `Evidence` | Immutable source artifact plus provenance, capture time, reliability and content hash | Append-only `evidence_id` |
| `Fact` | Stable semantic subject/predicate identity | Stable `fact_id` |
| `FactRevision` | A typed value asserted for a fact, with truth level and evidence links | Append-only; may supersede a revision |
| `Metric` | Deterministic calculation over facts/other metrics | Definition in pack; result in session |
| `Assessment` | Criterion conclusion: status, score, severity, confidence and coverage | Append-only revision |
| `Finding` | Rule-backed diagnostically relevant conclusion | Append-only; links rule and inputs |
| `Hypothesis` | Testable causal explanation | Append-only lifecycle revisions |
| `RootCause` | A hypothesis that crossed the configured confirmation threshold | A role/status, not free LLM text |
| `Recommendation` | Structured response to findings/root causes | Definition plus session lifecycle |

Allowed truth levels for a `FactRevision`:

```text
OBSERVED   direct system/event observation
REPORTED   statement by a person or organization
CALCULATED deterministic calculation from observed/reported inputs
DERIVED    deterministic transformation or aggregation
INFERRED   probabilistic semantic/causal inference
ESTIMATED  approximate value with an explicit method/range
ASSUMED    temporary premise that still requires validation
```

Truth level describes how a statement is known, not whether it is correct. Reliability, confidence and contradictions remain separate dimensions.

## 4. Evidence provenance and traceability

Evidence is immutable. A changed source, refreshed export or corrected interview statement creates new evidence. Minimum provenance fields are:

```text
evidence_id, evidence_type, source_kind, source_uri, captured_at,
observed_at, collector, content_hash, reliability, metadata
```

The canonical traceability path is:

```text
Observation -> Evidence -> FactRevision -> MetricResult -> Assessment
-> Finding -> Hypothesis -> Recommendation -> Decision -> Action
-> Result -> Measurement -> Evidence
```

Every derived node records direct upstream IDs and the definition/rule ID that produced it. Transitive provenance is obtained by graph traversal. Dangling references and cycles in a session traceability graph are invalid.

## 5. Criterion assessment state

Status is never encoded in `score`.

| Status | Precise meaning |
|---|---|
| `NOT_STARTED` | No relevant input has been collected |
| `INSUFFICIENT_DATA` | Some relevant input exists but minimum evidence/coverage is not met |
| `ASSESSED` | Assessment is valid but the pack deliberately defines no health band |
| `GOOD` | Valid assessment falls in the acceptable band |
| `WARNING` | Valid assessment indicates material weakness |
| `CRITICAL` | Valid assessment indicates severe or urgent weakness |
| `NOT_APPLICABLE` | Applicability rule is false and rationale is recorded |
| `CONTRADICTORY` | Material unresolved inputs prevent a defensible conclusion |

An assessment always stores these dimensions separately:

```text
status     categorical epistemic/health state
score      optional normalized 0..100 value
severity   NONE / INFO / LOW / MEDIUM / HIGH / CRITICAL
confidence 0..1 support strength
coverage   0..1 required-input coverage
```

`GOOD`, `WARNING`, `CRITICAL` and `ASSESSED` require minimum coverage and confidence configured by the criterion. `CONTRADICTORY` takes precedence over a health band when a material contradiction is unresolved. `NOT_APPLICABLE` is only produced by an explicit applicability rule.

## 6. Confidence and coverage

Coverage is deterministic:

```text
coverage = sum(weight of satisfied required inputs)
           / sum(weight of all applicable required inputs)
```

Default fact confidence composition is:

```text
confidence = source_reliability
           * evidence_directness
           * freshness
           * sample_quality
           * consistency
```

Each factor is `0..1`, stored with its reason and inputs. Packs may configure weights or a different registered deterministic function. LLM self-confidence may be an additional bounded factor, never the whole confidence value. Assessment confidence is calculated from contributing fact/metric confidence, coverage and contradiction penalties.

## 7. Hypothesis and root-cause lifecycle

```text
UNVERIFIED -> SUPPORTED -> STRONGLY_SUPPORTED -> CONFIRMED_ROOT_CAUSE
     |            |               |
     +------------+---------------+-> REJECTED
```

Transitions are deterministic and pack-configured. At minimum they consider supporting evidence count/weight, contradicting evidence, causal dependency path, freshness and coverage. `CONFIRMED_ROOT_CAUSE` requires the pack's root-cause threshold and cannot be assigned directly by an LLM.

## 8. Recommendation lifecycle

```text
PROPOSED -> ACCEPTED -> PLANNED -> IN_PROGRESS -> IMPLEMENTED
                                                    |
                                                    v
                                                 MEASURED
                                                /        \
                                         SUCCESSFUL     FAILED
```

Every recommendation links triggering findings/hypotheses, target criteria, expected impact, effort, prerequisites, actions and metrics to watch. Operational transitions after `ACCEPTED` may be owned by another COS domain; Diagnostic retains the correlation and measurement trail.

## 9. Adaptive interview contract

Questions are a library, not a fixed script. Eligibility and ranking are deterministic; natural-language rendering may be AI-assisted.

```text
priority = diagnostic_impact
         * uncertainty_reduction
         * criterion_importance
         * expected_information_gain
         * dependency_value
         / max(question_cost * user_fatigue, epsilon)
```

The engine excludes answered, inapplicable, blocked and redundant questions. `dependency_value` counts the weighted downstream facts, metrics, criteria and rules that the answer can unlock. Every ranking result stores its component values so it is explainable and replayable.

## 10. Diagnostic Pack contract

The normative machine contract is [`diagnostic-pack.schema.json`](../diagnostic/diagnostic-pack.schema.json). A pack contains:

```text
identity and target
areas
criteria
questions
evidence requirements
facts
metrics
rules
scoring
dependencies
hypothesis policies
recommendations
confidence policy
```

References use stable lowercase IDs. Pack content contains no executable PHP, SQL or arbitrary expressions. Conditions use the closed expression grammar defined by the schema and compiler.

## 11. Compiler pipeline

Loading a JSON/YAML document is not publication.

```text
decode
-> JSON Schema validation
-> identity and uniqueness validation
-> reference validation
-> type/unit validation
-> rule/operator validation
-> dependency and metric cycle detection
-> reachability/coverage warnings
-> normalization
-> immutable CompiledDiagnosticPack
```

Compilation errors include at least unknown references, duplicate IDs, invalid units/types/operators, self-dependencies, circular dependencies, unreachable required criteria, rules without outputs, recommendations without triggers and questions that cannot produce an input. Publication accepts only a compiled artifact and stores the schema/compiler version plus a canonical content hash.

## 12. Deterministic versus AI responsibility

| Deterministic | AI-assisted proposal |
|---|---|
| Metric calculation and units | Language understanding |
| Rule evaluation | Fact extraction candidate |
| Score, confidence and coverage | Semantic normalization candidate |
| Dependencies and applicability | Question wording |
| State transitions and eligibility | Contradiction interpretation |
| Question ranking components | Hypothesis proposal |
| Root-cause confirmation threshold | Narrative and recommendation customization |

AI output must validate against a typed contract, cite evidence/upstream IDs and pass deterministic eligibility rules before it enters session state.

## 13. Implementation map and migration from the current skeleton

The existing `DiagnosticPack`, `DiagnosticSession`, immutable `Evidence`, traceability checks, `PackLoader` and `PackValidator` are retained as the starting point. The next implementation increments are:

1. Split generic `DiagnosticRecord` into typed records or typed payloads with explicit truth/status/confidence semantics.
2. Add append-only `FactRevision`, assessment and hypothesis/recommendation lifecycle models.
3. Add deterministic `DiagnosticStateBuilder` and criterion state reducer.
4. Replace direct pack publication with `PackCompiler -> CompiledDiagnosticPack -> publish`.
5. Expand loader/validator to the normative schema and add canonical hashing.
6. Add methodology fixtures and regression tests before creating Sales Pack v0.1.

This document is the semantic authority. PHP types and persistence schemas must not invent alternate meanings for the states above.
