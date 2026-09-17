# Sales Diagnostics

Sales-specific diagnostics live under `app/Domains/Sales/Diagnostics`. They own Sales methodology selection, versioning, deterministic evaluation, and Sales-specific evaluation datasets. They do not own AI collection logic.

The generic `Domains/Diagnostic` capability remains the canonical engine and runtime model. Concept mapping is:

- Diagnostic -> generic `DiagnosticSession` / `DiagnosticRecord`
- DiagnosticArea -> generic `SectionDefinition`
- Criterion -> generic `CriterionDefinition`
- Question -> generic `QuestionDefinition`
- Fact -> generic `Model\\Fact`
- Metric -> generic `MetricDefinition`
- Evidence -> generic `Model\\Evidence`
- Score -> generic `DiagnosticResult` / criterion and section scores
- Finding -> generic `Model\\Finding` or methodology result finding
- Recommendation -> generic `Model\\Recommendation`
- Report -> generic Diagnostic Report capability

AI may collect evidence, infer candidate facts, interview users, and formulate explanations. AI must not calculate the canonical score or redefine methodology rules. `SalesDiagnosticEvaluator` delegates scoring and validation to the deterministic generic `MethodologyEngine`.

The legacy `Domains\\Diagnostic\\Evaluation\\SalesEvaluationRunner` remains as a deprecated adapter and delegates to `Domains\\Sales\\Diagnostics\\Evaluation\\SalesEvaluationRunner`.
