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

## V0.6 Opportunity & Decision Intelligence runtime

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

Still intentionally absent: concrete external provider adapters, AI-assisted research agents, outbound engagement, cross-domain handoff acceptance, public API and Growth UI.
