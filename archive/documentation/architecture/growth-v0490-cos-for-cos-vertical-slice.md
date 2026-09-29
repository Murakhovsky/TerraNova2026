# Growth V0.49 — COS-for-COS Vertical Slice

V0.49 is the first explicit end-to-end acceptance slice where COS Growth is evaluated as a customer-acquisition system for COS itself, not as a collection of individually respectable classes that have never met each other.

## Canonical flow

```text
Market → Account → Signal → WHY NOW → Opportunity → Committee → Outreach → Reply → Route → Sales → Outcome → Learning
```

The scenario is intentionally ordinary B2B acquisition. A configured Market Universe sources a company matching the active COS ICP. Provider facts enrich the Growth Account and create an immutable snapshot. ICP fit decides whether the Account is ignored or monitored. **Signal != Opportunity** remains mandatory: the Account does not become an OpportunityCandidate until Growth owns an observed account Signal.

Once the Signal exists, the existing Growth runtime owns the rest of the path:

1. Market Discovery identifies/reuses the Account and computes ICP fit.
2. A canonical Signal opens the evidence gate and materializes the OpportunityCandidate.
3. Research produces evidence-bound problem hypothesis and WHY NOW.
4. Qualification evaluates independent Fit / Need / Timing / Access / Value dimensions.
5. Buying Committee intelligence identifies the relevant contact/champion context.
6. Engagement produces a governed next-best-action recommendation.
7. Governed execution sends the approved outreach through Growth-owned action/policy runtime.
8. An inbound reply is persisted as an observation, classified, then routed by deterministic authority.
9. A Sales route creates the Sales Lead through the Sales-owned application boundary.
10. V0.49 binds that routed Sales reference directly back to the Growth Candidate.
11. Sales qualification, meeting, win/loss and economic outcome events return through the durable feedback consumer.
12. Growth Learning records those outcomes for attribution, experiments and later optimization.

## Closure fixed in V0.49

Before this slice, accepted Growth handoff references were learnable, but a Sales Lead created from an inbound conversation route was not bound to the Growth learning graph. That meant the commercially most interesting path, “prospect replied, route to Sales”, could disappear from Growth attribution after Sales accepted it. Humans call this a funnel. Software occasionally interprets it as a set of unrelated databases.

V0.49 closes that gap in the conversation-routing transaction:

```text
authoritative route
    ↓
Sales/Service target accepts reference
    ↓
route status persisted
    ↓
GrowthLearningRepository::bindExternalSubject()
    ↓
growth.learning.binding_created
    ↓
future target-domain events resolve Candidate directly
```

The binding is tenant-scoped and idempotent. A reference already bound to another Candidate is a hard conflict, not a silent reassignment. Sales feedback first resolves direct learning bindings and only then uses the older accepted-handoff fallback.

## Acceptance invariants

- No cross-domain write from Growth to Sales persistence.
- No raw inbound reply body is copied into Sales routing.
- AI classification is advisory; deterministic routing remains authoritative.
- Market provider labels do not become Signals automatically.
- Candidate creation still requires canonical Signal evidence.
- Sales Lead creation remains target-domain idempotent.
- The routed Sales Lead is bound to the originating Growth Candidate before the routed event is published.
- Sales Lead → Deal conversion extends the same binding to `sales_deal`.
- Qualified, meeting, won and lost Sales events are normalized into Growth outcomes.
- Won value is learning/economic outcome data, not Finance-recognized revenue.
- Replaying a target event cannot rebind it to a different Candidate.

## COS-for-COS proof

For a COS customer-acquisition tenant, the expected visible proof is:

```text
high-fit Account
+ observed operational/technology/change Signal
+ evidence-backed WHY NOW
+ named buying contact
+ governed outreach
+ real reply
+ authoritative Sales route
+ Sales outcome
= attributable Growth learning record
```

This is the V0.49 golden path. It does not add Agent Orchestration, mass campaign semantics or a second CRM. Those are different problems and can wait their turn like civilized dependencies.
