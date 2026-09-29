# Growth Domain

Growth is the COS bounded context for market discovery, opportunity intelligence, governed engagement, response routing and learning from outcomes.

Canonical current documentation:

- `docs/04-domains/growth/overview.md`
- `docs/02-workflows/growth-opportunity-candidate-to-handoff.md`
- `docs/10-operations/growth-production-cutover.md`
- generated contracts under `docs/12-reference/`

## Core invariant

```text
Signal != Opportunity

Market evidence
→ OpportunityCandidate
→ Research / Qualification
→ Buying Committee
→ governed Engagement
→ Reply / Routing
→ Sales or Service
→ Outcome Feedback
→ Learning
```

Growth owns opportunity evidence and pre-handoff decision intelligence. It does not own Sales deals, Service tickets or their persistence.

Version-by-version implementation history is intentionally not maintained in this Domain README. Git and the repository archive preserve that history; this file points developers to current truth.
