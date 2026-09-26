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

V0.28 adds the first credentialed provider-neutral pull collector:

```text
Tenant JSON Signal Source
  HTTPS endpoint
  bearer | X-* API key auth
  opaque credential_reference
        ↓
Platform CredentialVault
        ↓
safe public HTTPS transport
        ↓
canonical JSON envelope
        ↓
credentialed_json collector
        ↓
Signal + source dedupe + run history + Events/Audit
```

Growth persists only the credential reference, never raw token or API key material. The first concrete vault adapter resolves `env://VARIABLE_NAME` where the environment value is a JSON secret object such as `{"token":"..."}` or `{"api_key":"..."}`.

The provider response contract is intentionally narrow:

```json
{
  "items": [
    {
      "id": "stable-provider-id",
      "occurred_at": "2026-09-24T12:00:00+00:00",
      "source_reference": "https://provider.example/events/42",
      "facts": { "kind": "funding", "stage": "series_a" }
    }
  ]
}
```

Vendor-specific payload translation belongs outside the Growth Domain. The transport is HTTPS/443 only, resolves public IPv4, pins DNS, follows no redirects, caps the response at 2 MB and uses the canonical ExternalCall resilience runtime.

V0.29 makes V0.28 operational inside the existing Collectors workspace:

```text
/growth/collectors
  ├─ RSS / Atom feeds
  ├─ Credentialed JSON API sources
  ├─ create source
  ├─ enable / disable
  ├─ registered collectors
  └─ run history
```

The SSR controller reads JSON source state through `GrowthJsonSignalSourceBoundary::sources()`. Browser mutations use only `/api/v1/growth/json-signal-sources/*` with the existing CSRF and idempotency contract.

Stored credential references are never rendered back into the workspace. The create form accepts a new opaque reference once; subsequent rows expose only credential-configured state, auth mode/header and source mapping.

V0.30 closes the first automatic market-monitoring loop:

```text
Symfony Scheduler
        ↓
RunGrowthSignalPollingCommand
        ↓ async Messenger
worker
        ↓
enabled Growth source target index
        ↓
GrowthSignalCollectorBoundary
        ├─ rss_atom
        └─ credentialed_json
        ↓
canonical run history + source dedupe + Signal + Events/Audit
```

Polling is disabled by default. Enabling it requires an explicit system actor id. The scheduler only creates due commands; network-bound collector work runs in the normal async worker.

The polling target read model reads only organization ids and collector kinds from enabled Growth sources. It does not load URLs, credential references or provider payloads. Each cadence bucket produces stable collector idempotency keys, while source receipts remain the durable content-level dedupe mechanism.

V0.31 exposes scheduled monitoring health in the existing Collectors workspace:

```text
/growth/collectors
  ↓
GrowthSignalPollingStatusProvider
  ↓
current tenant only
  ├─ scheduler enabled / disabled
  ├─ ready / reason
  ├─ cadence
  ├─ system actor configured? (boolean only)
  ├─ Growth module state
  ├─ per-run signal limit
  └─ enabled RSS / JSON source counts
```

The workspace does not enumerate other organizations and never renders the configured actor id, source credentials or cross-tenant scheduler limits. Scheduler configuration remains deployment-owned; the SSR surface is read-only.

V0.32 makes scheduled monitoring failure-aware:

```text
scheduled collector
      ↓
collector result
  ├─ completed → healthy, failure streak reset
  ├─ partial   → degraded, no transport cooldown
  └─ failed    → consecutive failure + exponential backoff
                                      ↓
                               next_retry_at
                                      ↓
                         scheduler skips cooldown
```

Health is stored per tenant + collector, not per credential or provider secret. Backoff starts at the configured polling cadence, doubles on consecutive transport failures and is capped by `COS_GROWTH_COLLECTOR_MAX_BACKOFF_MINUTES`. The Collectors Workspace exposes only operational health: last run status, consecutive failures, last success/failure and next retry.

V0.33 promotes sustained transport failure into an explicit operator incident:

```text
failure streak < threshold
        ↓
health only
        ↓ threshold crossed
OPEN collector incident
        ↓ more failures
same incident updated
        ↓ provider transport recovers
RESOLVED + recovery event
```

Only one open incident may exist for a tenant + collector. Historical resolved incidents remain immutable rows. Opening and recovery emit `growth.collector.incident_opened` / `growth.collector.incident_resolved` and Audit records. The failure threshold is deployment-owned through `COS_GROWTH_COLLECTOR_INCIDENT_FAILURE_THRESHOLD`.

V0.34 adds explicit tenant-owned email alert subscriptions. A Growth manager provides the operational email address and may enable/disable it in the Collectors workspace. Growth deliberately does not query Identity tables to guess who an administrator might be.

Incident notifications are queued only after the incident transaction commits. Delivery goes through Platform Notification using the built-in `growth.collector.incident` email template. Alert transport errors are isolated from incident persistence, so a broken mail provider cannot make the monitoring incident disappear.

V0.35 extends the governed pre-handoff execution bridge to LinkedIn and phone calls without granting Growth autonomous outreach authority:

```text
Accepted EngagementRecommendation
        ↓
explicit channel identity on GrowthContact
  ├─ email    → growth.send_message
  ├─ linkedin → growth.send_linkedin
  └─ phone    → growth.place_call
        ↓
Kernel ActionProposal
        ↓
Growth Action Policy = APPROVAL_REQUIRED
        ↓
approved execution
        ↓
channel handler resolves identity at execution time
        ↓
email → Platform Notification
linkedin / call → Platform Integration outbox → n8n adapter workflow
```

LinkedIn requires an HTTPS `linkedin.com/in/*` contact identity. Calls require E.164 phone identity. Recipient identities are not copied into Kernel Action parameters or Growth execution-link persistence; the external identity is resolved only by the approved Action handler and then placed in the durable integration delivery envelope required by the transport.

The LinkedIn and call adapters are provider-neutral. Growth does not embed LinkedIn scraping, browser automation or a telephony SDK. The existing n8n integration outbox emits `growth.engagement.linkedin` and `growth.engagement.call` events, allowing deployment-specific connector workflows to perform the final provider call with the same durable retry/idempotency semantics.

Post-handoff phone execution remains owned by Sales. Growth refuses to route a phone recommendation through `sales.send_message`.

V0.36 closes the async execution observability gap for LinkedIn and phone adapters:

```text
approved Growth Action
        ↓
n8n integration outbox
        ↓
provider / connector
        ↓
signed delivery callback
POST /webhooks/growth/engagement/delivery
        ↓
action_id → Growth execution link
        ↓
append-only delivery observation
        ↓
Candidate Workspace latest delivery state
```

The callback is HMAC-signed, timestamp-bounded and idempotent. It records normalized provider feedback only for Growth-owned pre-handoff executions. LinkedIn accepts `accepted / sent / delivered / failed`; phone accepts `accepted / started / completed / no_answer / busy / failed`. Post-handoff Sales actions are rejected by this callback and remain Sales-owned.

Delivery observations intentionally persist no outbound body, email, phone number or LinkedIn profile URL. They retain execution provenance, normalized status, optional provider reference/reason and occurrence time. The Candidate Workspace can now distinguish "Kernel Action queued" from "external channel actually progressed", because apparently humans eventually notice that those are not the same thing.

V0.37 adds hard pre-handoff outreach guardrails before any future autonomous mode is even considered:

```text
accepted recommendation
        ↓
identity + channel validation
        ↓
pre-handoff execution limits
  ├─ organization daily cap
  └─ contact cooldown
        ↓
Kernel ActionProposal
        ↓
APPROVAL_REQUIRED policy
```

Limits are enforced before a new Growth-owned Action is proposed, and the same decision is exposed through execution eligibility so the Workspace can explain why outreach is blocked and when a cooldown expires. The current guardrails count proposed pre-handoff executions, not only successfully delivered messages, which is deliberately conservative: a pile of pending approvals is still a pile of attempted outreach.

Configuration remains deployment-owned through `COS_GROWTH_OUTREACH_DAILY_LIMIT` and `COS_GROWTH_OUTREACH_CONTACT_COOLDOWN_HOURS`. This does **not** introduce autonomous outreach, automatic approval or background prospect blasting. Humanity survives another release.

V0.38 moves outreach limits from deployment-only defaults into an append-only tenant-owned profile:

```text
deployment defaults
        ↓
tenant Growth Settings
        ↓ explicit managed update
append-only limit profile revision
        ↓
GrowthEngagementLimitProvider
        ↓
pre-handoff execution eligibility / proposal
```

Each organization can set its own daily pre-handoff execution cap and contact cooldown with an explicit change reason. Every change creates a new immutable revision, Event and Audit record. Execution reads the latest tenant profile and falls back to deployment defaults only when no tenant revision exists.

The canonical API is `GET/POST /api/v1/growth/engagement/limits`; the operator surface is `/growth/settings`. None of this grants `AUTO` execution authority: Kernel Action approval remains a separate mandatory gate. We have therefore achieved the rare feat of adding more settings while making the system less dangerous.

V0.39 adds channel-specific pre-handoff quotas on top of the tenant-wide cap:

```text
tenant outreach profile
  organization daily limit
  contact cooldown
  email daily quota
  LinkedIn daily quota
  phone daily quota
        ↓
execution eligibility
  total usage today
  channel usage today
  last contact execution
        ↓
allow / block with explicit scope + next_allowed_at
```

The organization-wide limit remains the hard ceiling across all channels. Each channel quota can independently be set to `0` to block new pre-handoff execution on that transport without disabling Growth research or recommendations. Existing V0.38 API clients that omit channel quotas remain valid; missing values inherit the effective policy and are clamped when the organization-wide cap is reduced.

Quota enforcement is still deliberately conservative and counts proposed Growth-owned execution links. A pending approval therefore consumes quota. This avoids the charmingly human loophole of queueing a thousand messages first and asking whether the daily limit mattered afterward.

Still intentionally absent: HR/Procurement target adapters and autonomous outreach/activation. V0.40 closes the concurrency blocker; execution authority remains a separate policy concern.


V0.40 makes pre-handoff quota admission concurrency-safe instead of merely optimistic:

```text
parallel execution proposals
        ↓
canonical DB transaction
        ↓
tenant-wide Growth capacity row
        ↓ SELECT ... FOR UPDATE
authoritative quota + cooldown re-check
        ↓
Kernel Action proposal
        ↓
execution link + Event + Audit
        ↓
single atomic commit
```

The lock is tenant-wide rather than day-scoped. That deliberately serializes only the short pre-handoff admission critical section and also closes the midnight race where two different UTC-day buckets could otherwise bypass a contact cooldown.

The initial eligibility check remains a fast operator hint. The check performed after the database lock is authoritative. If another proposal used the last organization or channel slot while this request was waiting, the waiting request is rejected before a Kernel Action exists.

No separate durable reservation ledger is required: the canonical transaction itself is the reservation boundary. `ActionPolicyService` joins an already-active transaction, so the capacity lock, policy-governed Action, execution link, Event and Audit either commit together or roll back together. Pending approval continues to consume quota because its execution link is committed.

This still does **not** enable autonomous outreach. V0.40 removes the concurrency blocker; execution authority remains `APPROVAL_REQUIRED`.


V0.41 adds tenant-owned outreach authorization independently from volume limits:

```text
Growth Settings
  email      → blocked | approval_required | auto
  LinkedIn   → blocked | approval_required | auto
  phone      → blocked | approval_required | auto
        ↓
append-only activation profile
        ↓
tenant capacity lock
        ↓
authoritative activation + quota/cooldown admission
        ↓
Kernel policy context: growth.activation_mode
        ↓
DENIED | APPROVAL_REQUIRED | AUTO
```

The default for every channel remains `approval_required`. A tenant may block a transport entirely, keep manager approval, or allow a user-triggered execution proposal to queue automatically. `AUTO` here does not mean background autonomous prospecting: V0.41 does not select prospects, accept recommendations, author messages or initiate execution on its own.

Activation changes use the same tenant-wide database lock as pre-handoff capacity admission. Therefore an activation update and an execution proposal have a deterministic order: if the block commits first, the execution sees it; if an already-admitted execution holds the lock first, it completes under the policy that was authoritative at its admission point.

Kernel governance remains authoritative. Growth passes the resolved activation mode into policy context, while `GrowthPolicyCatalog` maps that context to `DENIED`, `APPROVAL_REQUIRED` or `AUTO`. The existing approval policy identifiers are preserved so normal module upgrade provisioning converts the former unconditional approval policies into conditional ones instead of leaving duplicate policy ghosts behind.


V0.42 adds the first controlled autonomous initiation runtime. It deliberately separates **content authorization** from **execution timing**:

```text
human/operator stages payload
        ↓
tenant autonomy policy (default OFF)
        ↓
deployment scheduler (default OFF)
        ↓
recommendation status + confidence + channel eligibility
        ↓
channel activation must be AUTO
        ↓
tenant capacity lock
        ↓
contact identity + org quota + channel quota + cooldown
        ↓
existing Kernel Action policy/runtime
        ↓
provider adapter
```

Autonomy profiles are append-only and define `enabled`, `min_confidence`, allowed channels, allowed recommendation states and `max_actions_per_run`. Safe defaults are disabled, 0.90 minimum confidence, email only, accepted recommendations only, and five actions per scheduler run.

V0.42 does **not** let an LLM author final outreach copy at trigger time. The existing Engagement Intelligence contract still produces a strategic `message_angle`, not a sendable message. Autonomous execution therefore requires a separately staged, operator-approved payload. Payload revisions are append-only and auditable. This is intentionally less magical and considerably less likely to email a customer an internal reasoning note.

A tenant may explicitly allow staged `proposed` recommendations. In that case the autonomous runtime records the normal recommendation acceptance first, then proceeds through execution admission. Dismissed and superseded recommendations can never be autonomously triggered.

Autonomy is double opt-in: `COS_GROWTH_AUTONOMY_SCHEDULER_ENABLED=1` is required at deployment level and the tenant's latest autonomy profile must also be enabled. The scheduler never bypasses V0.40/V0.41 controls. Profile changes, payload staging and trigger admission serialize on the same tenant-wide Growth capacity lock, so a concurrent disable/update has a deterministic order relative to an autonomous trigger.

Autonomous triggering is pre-handoff only in V0.42. If the recommendation resolves to an existing Sales deal, Growth skips it rather than silently crossing domain ownership.


## V0.43 — Autonomous Content Drafting & Review Policy

V0.43 removes the mandatory manual-copy bottleneck without collapsing reasoning, content generation and execution into one opaque AI gesture.

```text
Engagement Recommendation
        ↓
Governed Structured LLM
        ↓
versioned outreach draft
        ↓
deterministic leak/risk checks
        ↓
channel Content Review Policy
        ↓
blocked | human_review | policy_auto_approve
        ↓
approved V0.42 staged payload
        ↓
V0.42 autonomous trigger
```

Content generation uses its own prompt/schema and the governed Kernel `StructuredLlmClientInterface`. The content model receives only the accepted/proposed recommendation, its cited Signal evidence and sanitized account/contact context. Contact delivery identities are deliberately excluded. The generated body must not expose Candidate IDs, Recommendation IDs, evidence IDs, internal confidence/score language or unsupported facts.

The review profile is append-only and tenant-owned. It defines a review mode for email, LinkedIn and phone plus an auto-approval confidence threshold and maximum body length. Safe defaults are `human_review` on every channel, 0.92 minimum draft confidence and a 3000-character auto-approval limit.

`policy_auto_approve` is intentionally narrow. A draft is auto-approved only when:
- the current channel mode is `policy_auto_approve`;
- draft confidence meets the tenant threshold;
- the body stays within the configured limit;
- the LLM declares no risk flags;
- deterministic checks find no internal reference leakage.

Anything else remains pending for a human review. A current `blocked` mode cannot be overridden by approving an old draft.

Approved drafts are promoted through the existing V0.42 `stagePayload()` boundary rather than writing execution state directly. Therefore V0.43 does not bypass autonomy eligibility, activation mode, atomic quota admission, cooldown, Kernel policy, provider execution or delivery feedback.

Automatic drafting is another explicit deployment gate: `COS_GROWTH_CONTENT_SCHEDULER_ENABLED=0` by default. The content scheduler only considers organizations whose V0.42 tenant autonomy profile is enabled, and only pre-handoff recommendations that already satisfy autonomy/channel/activation eligibility. LLM generation happens outside the database transaction; current review policy and staging eligibility are re-checked under the tenant-wide Growth capacity lock before an auto-approved draft is promoted.

Human staging from V0.42 remains available as an explicit override path for operators. Generated content is a convenience layer, not a new source of execution authority.


V0.43 preserves actor provenance through the V0.42 staging boundary: scheduler-approved content stages as `SYSTEM`, while explicit operator staging remains `USER`. Deterministic review also blocks policy auto-approval when final copy leaks delivery identities such as email addresses, E.164-style phone numbers or LinkedIn profile URLs.


Content generation uses a single active-run lease per recommendation. The database rejects concurrent LLM generation before a second request can spend budget; a run left active by a crashed worker is released after 15 minutes. A recommendation with a `pending_review` draft cannot generate another draft until that draft is decided, preventing API callers from quietly bypassing the review queue.


The V0.43 drafting context is data-minimized before it reaches the model. Candidate/recommendation/contact/account identifiers, recommendation confidence, expected deal value and technical snapshot metadata are excluded from the LLM payload. Internal identifiers required for deterministic leak detection are kept in a separate validation bundle and never sent to the content model.


The drafting payload also omits signal confidence and ICP match scoring. The model gets observable account/contact facts and the selected message angle, not the internal machinery that decided the prospect was attractive.


## V0.44 — Autonomous Outreach Sequences & Follow-up State Machine

V0.44 turns autonomous outreach from a one-shot trigger into a bounded follow-up lifecycle:

```text
initial governed execution
        ↓
tenant sequence policy
        ↓
sequence touch 1
        ↓
wait configured delay
        ↓
central Sequence Guard
  ├─ reply / downstream outcome
  ├─ Sales handoff
  ├─ failed delivery
  ├─ completed phone conversation
  ├─ sequence/autonomy policy change
  ├─ channel no longer AUTO
  └─ content review blocked
        ↓ allowed
derived immutable recommendation
        ↓
V0.43 draft + review
        ↓
V0.42 payload + trigger
        ↓
V0.40 quota / cooldown admission
        ↓
next touch or completion
```

Each follow-up is a new immutable Engagement Recommendation and receives its own governed execution. V0.44 never reuses the first recommendation for a second Action. Sequence steps preserve root recommendation, parent recommendation and touch number so attribution and audit remain readable rather than becoming the traditional enterprise bowl of spaghetti.

Sequence policy is tenant-owned and append-only. Safe defaults are OFF, email + LinkedIn allowed, 3 total touches, 72 hours between touches and 10 advances per scheduler run. `max_touches` includes the first outreach.

Enabling sequences creates an `activation_started_at` window. Only pre-handoff executions created after that timestamp may bootstrap a sequence. Changing limits while still enabled preserves the window; disabling and later re-enabling creates a new one. Historical outreach therefore does not suddenly wake up because somebody found a checkbox.

A central `GrowthOutreachSequenceGuard` is shared by the sequence scheduler, V0.43 content admission and V0.42 trigger admission. This closes the gap between scheduler ticks: a reply, Sales handoff, policy disable, channel block or delivery failure that arrives after a follow-up recommendation was created still prevents its content/trigger path from proceeding.

Authoritative sequence stop signals are:
- any Sales `sales_deal` binding;
- Growth learning outcomes `reply_received`, `qualified`, `disqualified`, `meeting_completed`, `won` or `lost` observed after sequence start;
- any sequence touch with delivery status `failed`;
- phone delivery status `completed`;
- sequence policy disabled or channel removed;
- autonomy disabled / confidence or state no longer eligible;
- channel activation no longer `AUTO`;
- content review mode changed to `blocked`;
- explicit operator stop.

A completed phone call stops the autonomous lane because the next step belongs to conversation handling, not a blind timer. Email currently has no Growth delivery webhook, so its follow-up clock anchors to the governed execution timestamp. LinkedIn and phone use the latest delivery observation when available.

Derived follow-up recommendations are deterministic. They reuse the root action, channel, contact and evidence, add `reply_status_unknown`, and explicitly instruct V0.43 not to claim that a previous message was read or remembered. The state machine itself never calls an LLM; content generation remains V0.43's job.

Stopping a sequence is an execution boundary, not a cosmetic status. Pending V0.42 payload discovery excludes inactive sequence recommendations, V0.42 trigger admission uses the central guard, and V0.43 content generation/review uses the same guard.

The sequence scheduler has its own deployment kill switch and defaults OFF: `COS_GROWTH_SEQUENCE_SCHEDULER_ENABLED=0`.


For channels with V0.36 delivery callbacks, the timer does not start merely because a Kernel Action exists. LinkedIn and phone wait for the first provider delivery observation; email remains the deliberate exception and uses execution time because Growth has no email delivery callback yet.

Disabling the tenant sequence policy blocks V0.42/V0.43 admission immediately through the central guard. The sequence scheduler also continues selecting organizations that still have active sequence rows, even when their latest sequence policy is disabled, so those rows are reconciled to `stopped` instead of becoming immortal "active" ghosts.

V0.43 performs the central sequence guard twice around model work: once before spending LLM budget and again under the tenant capacity lock after generation. A reply or handoff arriving while the model is drafting therefore prevents auto-approval/staging. Human rejection of an already-pending draft remains allowed after the sequence stops so operators can clean up stale review work.


Phone provider states `accepted` and `started` are explicitly non-terminal. They keep the sequence waiting; only `no_answer` or `busy` may start another follow-up delay, while `completed` and `failed` stop the autonomous lane.


## V0.45 — Inbound Response & Conversation Signals

V0.45 closes the return path of autonomous outreach. Growth observes a real reply before it tries to interpret what the reply means.

```text
provider reply
  ↓ signed response webhook
kernel_action_id correlation
  ↓ tenant pre-handoff capacity lock
append-only Engagement Response fact
  ↓ reply_received outcome
Event + Audit + commit
  ↓ V0.44 Sequence Guard
further follow-up blocked
  ↓ durable Event Outbox
structured response classification
  ↓
intent / sentiment / urgency / requested action / advisory next owner
```

The canonical edge is `POST /webhooks/growth/engagement/responses`. It uses a dedicated HMAC secret, timestamp window and idempotency key. A response must correlate to an existing Growth-owned pre-handoff Kernel Action. Email maps to `growth.send_message`, LinkedIn to `growth.send_linkedin`, and phone to `growth.place_call`. Sales-owned executions are rejected because post-handoff conversation ownership belongs to Sales.

Response ingestion is deliberately independent from AI availability. The response fact and `reply_received` outcome commit first under the same tenant-wide admission lock used by V0.40–V0.44. This gives a reply and a concurrent autonomous trigger a deterministic order.

Observed response content and interpretation are stored separately:
- `tn_growth_engagement_responses` owns the factual inbound body, provider/thread references, correlation and timestamp;
- `tn_growth_engagement_response_classifications` owns versioned AI interpretation.

The raw response body is not copied into Event or Audit payloads. Those surfaces receive references, hashes and normalized metadata. The classifier receives the response body plus limited prior message angle and Candidate business context, without contact delivery identities or internal ids.

Classification runs through the governed structured LLM boundary from durable consumer `growth.response-classification.v1`. Provider, model, prompt/schema versions and usage metadata are persisted. A future prompt/schema can create another immutable interpretation without rewriting the observed response.

Classification vocabulary includes interested, question, meeting_request, objection, not_interested, unsubscribe, referral, wrong_person, out_of_office and other; sentiment is positive/neutral/negative/mixed/unclear; urgency is low/normal/high.

`recommended_next_owner` is advisory only. V0.45 does not dispatch a handoff, send an automatic reply or mutate Sales/Service state from model output. Those actions require a separate conversation-routing authority policy.

The Candidate Workspace and `GET /api/v1/growth/candidates/{id}/engagement/responses` expose observed replies with the latest classification. An unclassified response remains a valid visible fact while durable classification retries.

AI classification is not required to stop outreach. Receiving the reply itself records `reply_received`, so V0.44's central Sequence Guard blocks content generation and trigger admission immediately.


V0.45 also closes the manual governed-execution gap for sequence-derived recommendations. `GrowthEngagementExecutionService` now applies the sequence hard block in eligibility, before proposal, and again after the tenant capacity lock. A human may override automation modes through an explicit governed action, but cannot use a stale follow-up recommendation after a reply, Sales handoff, operator stop, failed delivery or completed phone conversation.
