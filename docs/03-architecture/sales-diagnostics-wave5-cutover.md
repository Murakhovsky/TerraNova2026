# Sales Diagnostics Wave 5 cutover

Wave 5 makes Symfony the canonical HTTP/application boundary for Sales Diagnostics while preserving the existing Diagnostic domain and MySQL persistence.

| Roadmap | Scenario | Canonical API | Boundary |
| --- | --- | --- | --- |
| 22 | Diagnostic creation | `POST /api/v1/diagnostics` | `StartDiagnosticCommand` |
| 23 | Interview | `GET .../interview/next`, `POST .../interview/answers` | Query + command |
| 24 | Evidence → Fact/Metric | `POST .../evidence` | `DiagnosticEvidencePipeline` |
| 25 | Assessment | `POST .../complete`, `GET .../assessment` | deterministic evaluation |
| 26 | Findings | `GET .../findings` | report projection |
| 27 | Recommendations | `GET .../recommendations` | recommendation projection |
| 28 | Recommendation → Action | `POST .../recommendations/{recommendationId}/action` | governed COS Action |

## Ownership

```text
UI/API
  ↓
Symfony V1
  ↓
CommandBus / QueryBus
  ↓
Diagnostic Application
  ↓
Diagnostic Domain
  ↓
Ports
  ↓
Legacy MySQL adapters
```

No second Diagnostic engine is introduced. Existing methodology, interview extraction, deterministic criteria/rule evaluation, findings and recommendation lifecycle remain authoritative.

Evidence supports `interview`, `system_data` (CRM/metrics), `document`, `external_source`, `observation`, and `survey`. Structured evidence may carry `facts[]` and `metrics[]`; every derived value keeps its Evidence reference and contradictions are retained.

AI does not calculate the score:

```text
Evidence → Fact / Metric → Criteria → Rules → Assessment → Finding → Recommendation
```

Mutations require tenant context, active Diagnostic module, tenant permission, CSRF during the strangler phase, correlation id and `X-Idempotency-Key`. Creation uses a deterministic session id, interview retries are recorded by idempotency key, evidence uses a deterministic evidence id, completion is terminal-state idempotent, and Recommendation → Action uses Kernel Action idempotency plus `APPROVAL_REQUIRED`.

```text
Finding → Recommendation → COS Action → Approval → Execution → Outcome measurement
```
