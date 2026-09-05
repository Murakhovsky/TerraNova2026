# Diagnostic Phase 4-6

## Runtime flow

```text
DiagnosticState -> NextBestQuestionEngine -> InterviewCoordinator
-> AiGatewayInterface -> strict output validation -> candidate facts/evidence
-> contradiction detection -> deterministic MethodologyEngine
-> hypotheses -> evidence threshold -> root causes
-> methodology recommendation templates -> PriorityEngine
-> RoadmapBuilder -> DiagnosticReport -> ExplainabilityGraph
-> recommendation acceptance -> Kernel ActionService
-> result measurement -> re-diagnostic -> DiagnosticComparisonService
```

AI never writes domain state directly. `FactExtractionService` accepts only IDs defined by the compiled pack, validates value types and produces candidates. The coordinator converts validated candidates into evidence and facts before rebuilding state. Scores, coverage, confidence, rules, dependencies, question priority, recommendation priority and root-cause confirmation thresholds remain deterministic.

## AI operations and prompts

Operations: `conductInterviewTurn`, `extractFacts`, `detectContradictions`, `generateHypotheses`, `analyzeRootCause`, `generateRecommendations`, `generateExecutiveSummary`.

Prompt versions: `sales_interview:v1`, `fact_extractor:v1`, `contradiction_detector:v1`, `hypothesis_generator:v1`, `root_cause:v1`, `recommendation:v1`, `report_summary:v1`.

Machine responses are arrays validated against explicit schemas. No markdown extraction, regular-expression JSON recovery or direct persistence is permitted. `ContextSanitizer` removes common secret and PII fields. `RecordedAiGateway` stores hashes, token use, cost, latency and status without retaining raw context.

## Evaluation

`tests/fixtures/diagnostic/evaluation/sales-v0.1.json` contains 30 ground-truth scenarios. Exact evaluators cover classification precision/recall, false-positive rate, accuracy, hallucination rate, question relevance/redundancy, cost, prompt/methodology regression gates and before/after diagnostic comparison.

The executable acceptance contract is `tests/smoke/diagnostic_phase_4_6.php`.

## Deferred infrastructure

- Production OpenAI HTTP transport and persistent AI audit repository wiring.
- Web/API controller and PDF renderer for the structured report.
- Persistent interview-turn and contradiction repositories.
- Action outcome adapter that records measured results as new diagnostic evidence.
- Human-review UI and secondary LLM-as-judge evaluation.
