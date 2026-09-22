# Growth Domain

`Growth` is the COS Opportunity Intelligence bounded context. It turns observable market or business signals into researched, explainable and qualified `OpportunityCandidate` objects, then prepares an explicit handoff package for the Domain able to realize the opportunity.

## Canonical invariant

```text
Growth does not generate leads.

Growth identifies
→ researches
→ evaluates
→ prioritizes
→ routes business opportunities.
```

A signal, company or person is not an opportunity by itself. A candidate becomes handoff-ready only when COS can state:

```text
WHO / WHAT
+ WHY IT MATTERS
+ WHY NOW
+ EVIDENCE
+ EXPECTED VALUE
+ POSSIBLE ACTION
```

## Ownership

Growth owns:

- observable `Signal` facts and source references;
- opportunity research rationale and hypotheses;
- `OpportunityCandidate` lifecycle;
- explainable Fit / Need / Timing / Access / Value score dimensions;
- qualification and monitoring decisions;
- recommended play and next action;
- handoff package preparation;
- acquisition, expansion, reactivation and discovery modes.

Growth does **not** own:

- Sales deals or pipeline stages;
- negotiation, quote or contract lifecycle;
- invoices or payments;
- project/service delivery;
- customer support state.

Those remain with the target Domain after an explicit handoff.

## Fact / interpretation boundary

`Signal` contains observable facts only.

`OpportunityRationale` contains interpretation:

- why the facts matter;
- problem hypothesis;
- WHY NOW;
- supporting and counter evidence;
- assumptions;
- unknowns;
- confidence.

AI or research automation may propose a rationale, but it must not rewrite observed facts into certainty.

## V0.1 lifecycle

```text
DETECTED
  ↓
ENRICHING
  ↓
RESEARCHED
  ↓
SCORED
  ↓
QUALIFIED
  ↓
READY_FOR_HANDOFF
  ↓
HANDOFF_PENDING
  ↓
HANDED_OFF
```

Side states:

```text
MONITORING
DISQUALIFIED
DUPLICATE
EXPIRED
REJECTED_BY_TARGET_DOMAIN
```

## Growth modes

- `ACQUIRE` — find net-new customer opportunities;
- `EXPAND` — find new value inside existing customer relationships;
- `REACTIVATE` — reconsider old prospects, customers or previously mistimed opportunities;
- `DISCOVER` — find partners, suppliers, investors, candidates, tenders, properties, acquisitions, projects or technologies.

## V0.11 Executable Opportunity Intelligence Workspace

V0.3 adds versioned ICP profiles, Growth-owned Account identity, immutable evidence snapshots, deterministic evidence-backed ICP matching and an Account Brief that composes account facts with recent Growth signals and opportunities.

The intelligence chain is:

```text
ICP
→ GrowthAccount
→ immutable AccountSnapshot
→ deterministic ICP Match
→ Account Brief
→ OpportunityCandidate
```

Enrichment history is append-only at the snapshot level. A newer provider response does not silently rewrite what COS believed at an earlier decision point. ICP revisions are immutable business definitions; activating a new revision archives the previous active revision while historical matches keep their original `profile_revision` and scoring `model_version`.

V0.4 extends the chain with people and buying dynamics:

```text
GrowthAccount
→ GrowthContact identity + provenance
→ immutable ContactSnapshot
→ BuyingRole / RelationshipStrength
→ deterministic Buying Committee Assessment
→ Committee Brief
→ OpportunityCandidate / recommended play
```

The same person may participate in several accounts, while title, department, seniority, buying role and relationship are account-specific observations. Those observations are snapshots, not mutable properties of the human identity.

The committee assessment records required roles, coverage, gaps, champions, blockers, weak relationships, evidence snapshot ids and a deterministic model version.

V0.5 adds the provider-agnostic signal intake boundary:

```text
Provider adapter
→ SignalCollectorInterface
→ SignalCollectionBatch
→ source receipt / fingerprint dedupe
→ canonical Signal
→ collector run accounting
→ Growth intelligence
```

Provider calls happen outside database transactions. Each collected item is ingested in its own transaction, so one malformed or conflicting source record produces a partial run instead of rolling back unrelated accepted signals.

Collector source identity uses `collector_name + SHA-256(external_key)`; a repeated external key with identical payload is a duplicate, while reuse with a different normalized payload is rejected as a source conflict.

V0.6 adds deterministic qualification decision intelligence:

```text
Rationale + OpportunityScore
        ↓
versioned QualificationPolicy
        ↓
hard-reject thresholds
qualification minimums
research + score confidence threshold
        ↓
QUALIFIED / MONITOR / DISQUALIFIED
        ↓
immutable QualificationEvaluation
```

No canonical weighted "magic score" is introduced. Fit, Need, Timing, Access and Value remain independent evidence-backed dimensions. Every policy decision stores the exact rationale, exact score payload, policy id/revision, failed criteria, decision reason and `model_version`.

Policy-controlled evaluation also emits the normal candidate lifecycle event, so automated qualification does not create a parallel event vocabulary invisible to downstream consumers.

V0.7 adds evidence-bound structured research through the governed Kernel LLM boundary:

```text
Candidate + referenced Signals
+ sanitized Account / Committee context
        ↓
Kernel\Llm governed structured request
        ↓
ResearchProposal
  WHY IT MATTERS
  problem hypothesis
  WHY NOW
  evidence / counter-evidence
  assumptions / unknowns
  confidence
  provider / model / prompt / schema
        ↓
server-side evidence validation
        ↓
explicit proposal acceptance
        ↓
OpportunityRationale
```

Each research run persists the exact sanitized context snapshot seen by the model, making historical inference reproducible even after newer Account or Committee snapshots exist.

The model cannot write a Signal, qualify a Candidate or mutate the Candidate directly. Generated evidence identifiers must be a subset of the Candidate's existing Signal ids, and acceptance re-validates that constraint inside the mutation transaction.

The LLM context intentionally excludes contact email/LinkedIn identity. It may receive contact ids and account-specific role/relationship snapshots, which are sufficient for buying-committee reasoning without shipping personal contact identifiers into model context.

V0.8 adds a resumable target-domain handoff protocol:

```text
QUALIFIED
  ↓
READY_FOR_HANDOFF
  ↓
HANDOFF_PENDING
  ├─ target accepted → HANDED_OFF
  ├─ target rejected → REJECTED_BY_TARGET_DOMAIN
  └─ technical failure → READY_FOR_HANDOFF
```

Growth persists an immutable Opportunity Package per attempt and never writes directly to target-domain tables. Target adapters implement the Growth-owned `GrowthHandoffTargetInterface`, receive a stable Candidate-level idempotency key, and return either an accepted target reference or an explicit rejection reason.

A `running` attempt is resumable from its persisted package snapshot. If a process dies after entering `handoff_pending`, replaying the same Growth idempotency key resumes the target call rather than leaving the Candidate stranded. Concurrent resumes serialize on the Candidate row and return the already persisted outcome.

V0.9 provides the first concrete target adapter: `sales`.

For a Growth `contact` subject, Sales intake requires a valid email identity. For an `account` subject, the latest Buying Committee assessment must contain exactly one explicit champion, and that Growth contact must have a valid email identity. The adapter does not guess among multiple champions and does not treat LinkedIn identity as an email substitute.

When accepted, the adapter calls the target-owned `SalesWriteServiceFactoryInterface → createLead()` boundary with the stable Candidate-level idempotency key and returns a `sales_lead:<id>` reference. Growth never writes `tn_leads` or other Sales persistence.

V0.10 exposes the implemented Growth runtime through a thin Symfony API V1 surface:

```text
/api/v1/growth/signals
/api/v1/growth/candidates
/api/v1/growth/icp
/api/v1/growth/accounts
/api/v1/growth/qualification-policies
/api/v1/growth/handoff/*
```

The controller depends only on Growth application boundaries plus tenant/module/security services. Reads require tenant `ACCESS`; mutations require tenant `MANAGE`, valid session CSRF, a bounded `X-Idempotency-Key`, and correlation propagation. No repository or PDO access exists in the HTTP layer.

V0.11 adds the first Growth Workspace on the current COS Web Experience foundation:

```text
/growth
├─ /growth/candidates
│  └─ /growth/candidates/{id}
└─ /growth/accounts
   └─ /growth/accounts/{id}
```

Navigation, search, commands and workspace definitions are contributed through `growthNavigationContributor`, so the surface disappears automatically when the Growth module is disabled. SSR lists use a read-only Growth projection; detail pages compose existing application briefs. Candidate mutations call the canonical V0.10 API with CSRF and idempotency instead of duplicating lifecycle rules in the browser.

Still intentionally absent: HR/Procurement/Service target adapters, concrete external signal provider adapters and outbound engagement.
