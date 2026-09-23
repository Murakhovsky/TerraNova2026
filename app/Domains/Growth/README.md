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

## Current Opportunity Intelligence runtime (V0.24)

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

V0.12 adds operator-visible signal ingestion:

```text
/growth/signals
/growth/collectors
```

Signals expose observable evidence with source, confidence and Candidate usage. Collectors expose the registered adapter list and run history with accepted/duplicate/failed counters, cursors and error summaries. Running a collector still goes through `POST /api/v1/growth/collectors/{name}/run`; the SSR controller remains read-only.

V0.13 adds a signed push ingress for external market intelligence:

```text
external source / n8n / scraper
        ↓
POST /webhooks/growth/signals
        ↓
timestamp + HMAC-SHA256 + idempotency
        ↓
GrowthApplicationBoundary::ingestExternalSignal()
        ↓
canonical Signal + Event + Audit
```

The edge does not know Growth persistence. External signals use a dedicated idempotency namespace and are recorded with SYSTEM provenance. The Growth module must be enabled for the target organization.

V0.14 adds evidence-bound Next Best Action reasoning:

```text
Candidate + Signals + Rationale + Score
+ sanitized Account / Buying Committee context
        ↓
governed Kernel\Llm
        ↓
GrowthEngagementRecommendation
  action type
  channel
  contact_id
  rationale
  message angle
  evidence ids
  unknowns
  confidence
        ↓
server-side evidence/contact/channel validation
        ↓
Accept / Dismiss
```

The vocabulary is explicit: ignore, monitor, connect on LinkedIn, send email, call, offer diagnostic, send case study, ask introduction, invite webinar or create report. Contact email/LinkedIn identities are not sent to the model.

A recommendation is deliberately **not** a Kernel Action. V0.14 performs no outbound side effect. Future execution may convert an accepted recommendation into an ActionProposal only where a concrete action handler and Policy exist.

V0.15 closes the first cross-domain learning loop:

```text
Growth Candidate
   ↓ handoff accepted
sales_lead:<id>
   ↓ durable Sales events
LeadChanged
   ├─ contacted / qualified / disqualified outcome
   └─ client_case_id.to → bind sales_deal:<id>
                         ↓
                  deal won / lost
                         ↓
               GrowthOutcomeObservation
```

Growth stores normalized learning observations, not Sales business state. The feedback consumer never reads Sales persistence and never stores arbitrary message payloads. Won deal value is retained as economic outcome by currency; it is not treated as Finance-recognized revenue.

V0.16 makes the learning loop operationally visible:

```text
/growth/learning
  ├─ outcome funnel
  ├─ Candidates with feedback
  ├─ won value by currency
  ├─ lost / disqualified reason evidence
  └─ recent outcomes → Candidate drill-down

/growth/candidates/{id}
  └─ observed outcome panel
```

The Learning Workspace is read-only. It projects Growth-owned outcome observations and does not query or mutate Sales state.

V0.17 closes the learning loop without granting autonomous policy authority:

```text
Growth-owned terminal outcomes
+ score dimensions
+ ICP fit
+ signal performance
+ reason distribution
        ↓ deterministic aggregation
evidence ids + active target snapshots
        ↓
governed Kernel\Llm
        ↓
LearningOptimizationRecommendation
  target: ICP or Qualification Policy
  complete proposed criteria
  rationale / evidence / risks / assumptions
  confidence
        ↓
Accept / Dismiss
        ↓ explicit Materialize
new DRAFT revision
        ↓
separate human activation
```

The model does not calculate source metrics from raw rows and cannot activate a policy. Server-side validation requires a minimum terminal sample, an active target, valid complete criteria, non-no-op change and evidence ids from the deterministic learning context. Materialization reuses the existing ICP / Qualification application boundaries and creates only the next draft revision.

V0.18 exposes the V0.17 optimization runtime inside the existing `/growth/learning` workspace:

```text
Learning evidence
        ↓
Optimization recommendation
        ↓
Current criteria ↔ Proposed criteria
Risks / assumptions / confidence
        ↓
Generate / Accept / Dismiss
        ↓
Materialize draft
        ↓
Activation remains separate
```

The page uses the canonical Growth API for mutations with CSRF and idempotency. It does not add a second Web mutation path and intentionally exposes no activation control.

V0.19 adds controlled Growth Experiments and outcome attribution:

```text
Experiment
  hypothesis
  dimension
  primary outcome
  weighted variants
        ↓
DRAFT → RUNNING ↔ PAUSED → COMPLETED → ARCHIVED
        ↓
Candidate assignment
  deterministic weighted split
  or explicit manual variant
        ↓
Growth-owned downstream outcomes
        ↓
variant attribution
  reply / qualified / meeting / won / lost / disqualified
  primary conversion rate
  won value by currency
```

A Candidate may have only one immutable variant assignment per experiment. New assignments are rejected after a terminal Growth outcome. Attribution starts at `assigned_at`; a completed experiment freezes its window at `ended_at`. Counts use distinct Candidates, and won economic value uses the latest won observation inside the assignment window.

The runtime deliberately does not select a winner or execute the tested channel/message. Experiment measurement and execution remain separate authorities.

V0.20 exposes Experiments inside the existing Growth Workspace:

```text
/growth/experiments
  ├─ create DRAFT experiment
  ├─ filter lifecycle / dimension
  └─ open experiment

/growth/experiments/{id}
  ├─ lifecycle controls
  ├─ deterministic / manual Candidate assignment
  ├─ per-variant attribution
  ├─ primary conversion rate
  ├─ downstream outcome counts
  └─ won value by currency
```

The SSR controller is read-only. All create / transition / assignment mutations go through the canonical V0.19 API with CSRF and idempotency. The Workspace does not select a winner and does not execute the tested channel or message.

V0.21 adds governed Experiment Decision Intelligence after attribution is frozen:

```text
COMPLETED Experiment
        ↓
server-computed attribution
  assigned / primary rate
  downstream outcomes
  won value by currency
        ↓
deterministic evidence ids
        ↓
governed Kernel\Llm
        ↓
ExperimentDecisionRecommendation
  promote_variant / iterate / continue / stop / inconclusive
        ↓
server-side evidence / variant / sample validation
        ↓
Accept / Dismiss
```

The model never receives raw outcome rows and does not calculate conversion metrics. A `promote_variant` recommendation is rejected unless the experiment has at least 20 assigned Candidates in total and at least 5 assigned to every variant. This is a safety floor, not a claim of statistical significance.

An accepted experiment decision remains a recommendation. V0.21 does not mutate experiment lifecycle, activate a variant, archive the experiment, change ICP/policy, or execute outreach.

V0.22 adds the first governed outbound execution bridge, but only after Growth has a confirmed `sales_deal` binding:

```text
Accepted EngagementRecommendation
        ↓
exactly one Growth → sales_deal binding
        ↓
human-provided outbound body
        ↓
GrowthActionProposalGateway
        ↓
Kernel ActionProposal
  type = sales.send_message
  target = deal
  source = GROWTH
        ↓
Sales Action Policy
  default = APPROVAL_REQUIRED
        ↓
approval / queue / reject
```

Growth does not execute the Action and does not dispatch a worker command. The Application layer never imports Kernel Action/Policy classes; the concrete bridge lives behind a Growth-owned port. One recommendation can create at most one canonical Action payload. Growth stores only the execution link and payload fingerprint, not the outbound body.

V0.22 supports message-capable accepted recommendations on email or LinkedIn channels. `call`, `monitor`, `ignore` and `create_report` remain non-executable through this bridge.

V0.23 exposes the V0.22 execution bridge inside the Candidate Workspace. Operators can generate and decide a recommendation, inspect execution eligibility, provide the outbound body and propose the governed Action. Existing Kernel/Sales approval and execution authority is preserved; Growth UI never calls Sales approval or execute endpoints directly.

V0.24 extends governed engagement execution to the pre-handoff stage without inventing a Sales Deal:

```text
Accepted EngagementRecommendation
        ↓
execution target resolution
  ├─ exactly one sales_deal → sales.send_message
  ├─ zero sales_deal + email contact → growth.send_message
  └─ multiple sales_deal bindings → reject as ambiguous
        ↓
Kernel ActionProposal
        ↓
Action Policy
  growth.send_message = APPROVAL_REQUIRED
        ↓
approval / queue / reject
        ↓
GrowthSendMessageHandler
        ↓
GrowthOutboundMessageGateway
        ↓
Platform Notification
        ↓
durable n8n integration outbox
```

The pre-handoff action targets `growth_contact`, not a synthetic Sales Deal. Contact email is resolved and revalidated at execution time and is not copied into the Kernel Action parameters or Growth execution-link persistence. One recommendation still creates at most one canonical Action payload.

The first production pre-handoff transport is email. LinkedIn/call execution remains intentionally unavailable until a canonical transport adapter exists.

V0.25 proves the handoff protocol is not Sales-specific by adding a concrete `service` target:

```text
READY_FOR_HANDOFF
        ↓
target_domain = service
        ↓
ServiceGrowthHandoffTarget
        ↓
ServiceApplicationBoundary::createRequest()
        ↓
service_request:<id>
```

Growth maps the immutable Opportunity Package into a bounded Service Request subject/summary and a `growth:<subject_type>:<subject_id>` requester reference. It does not create Tickets, set SLA, assign work or touch Service persistence. Service remains the owner of decomposition and delivery lifecycle. The adapter is optional and rejects the handoff when the Service module is disabled.

V0.26 adds the first concrete pull collector with tenant-owned RSS/Atom feed configuration:

```text
Growth Signal Feed
  url + subject mapping + confidence
        ↓
rss_atom collector
        ↓
safe HTTPS transport
        ↓
RSS / Atom parser
        ↓
CollectedSignal
        ↓
canonical collector runtime
        ↓
Signal + source dedupe + Events + Audit
```

Feed URLs are restricted to HTTPS on port 443. The reader rejects private/reserved addresses, pins the resolved public IPv4 with `CURLOPT_RESOLVE`, follows no redirects and caps responses at 2 MB. The XML parser uses `LIBXML_NONET` and does not enable entity expansion.

RSS/Atom collection is intentionally cursorless: source receipts provide durable dedupe across repeated polling. Each tenant feed explicitly maps external entries to `subject_type`, `subject_id`, `signal_type` and confidence.

V0.27 makes tenant feed configuration operational in the existing Collectors workspace:

```text
/growth/collectors
  ├─ registered collectors
  ├─ RSS/Atom feed list
  ├─ create feed
  ├─ enable / disable
  └─ collector run history
```

The SSR controller reads feed state through `GrowthSignalFeedBoundary::feeds()`. Browser mutations use only the canonical `/api/v1/growth/signal-feeds/*` endpoints with CSRF and idempotency; the page controller does not create or toggle feeds itself.

Still intentionally absent: HR/Procurement target adapters, credentialed provider collectors, pre-handoff LinkedIn/call execution and autonomous activation.
