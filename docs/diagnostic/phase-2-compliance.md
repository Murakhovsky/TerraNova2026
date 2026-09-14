# Phase 2 — Methodology Engine compliance

Status: complete for the deterministic Phase 2 contract.

| Requirement | Implementation | Deterministic verification |
|---|---|---|
| Pack schema | `methodology-pack-phase2.schema.json`; immutable definitions under `Methodology/Model` | JSON/YAML fixtures compile to the same canonical hash |
| JSON/YAML loader | `PackLoader`; safe dependency-free YAML fallback with no tags, anchors or aliases | `diagnostic_methodology_contract.php` |
| Pack validator | IDs, references, types, operators, ranges, weights, score bands, dependency targets/cycles | Negative contract scenarios for unknown IDs, duplicates, empty groups, overlaps and cycles |
| Metric registry | `MetricRegistry::get/has/all` | Known and unknown lookup scenarios |
| Rule engine | Facts, metrics and assessments only; comparison/existence operators plus AND/OR/NOT | Every Phase 2 operator and combinator is exercised |
| Scoring engine | Bands, linear/inverse normalization, min/max, penalty, bonus and five aggregation modes | Band and linear regression scenarios |
| Coverage engine | Weighted required inputs and NONE/LOW/MEDIUM/HIGH/COMPLETE levels | Weighted 4/5 coverage scenario; missing required inputs block scores |
| Confidence engine | Reliability, quality, freshness, source count and agreement | Aligned versus contradictory source scenario; criterion confidence gate |
| Dependency engine | Validated acyclic graph plus upstream/downstream traversal | Cycle, self-reference and traversal scenarios |
| Diagnostic runner | `DiagnosticInput` or pinned `DiagnosticSession` to assessments/findings/scores | Session-to-result and lifecycle smoke scenarios |
| Traceability | Assessments and findings retain contributing evidence IDs | Lifecycle evaluation persists upstream/evidence references |

Score safety is intentionally strict: when any criterion in a section cannot produce a defensible score, the section and pack scores are `null`. Findings are also gated by the criterion's minimum coverage and confidence.

No LLM or unstructured-text interpretation participates in this pipeline.
