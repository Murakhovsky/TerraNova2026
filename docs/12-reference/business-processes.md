---
title: Business Process Registry
description: Generated registry of canonical COS business processes, ownership, capability coverage and evidence-backed runtime verification.
status: generated
updated: 2026-09-15
kind: reference
contract: reference-v1
generated: true
---

# Business Process Registry

Generated from `docs/.vitepress/processes/*.json`, canonical module capabilities and the current-checkout runtime evidence catalogue. Do not edit this page manually.

Business state, capability coverage and runtime verification are separate dimensions: a step may be executable in current code while its Domain capability vocabulary is still incomplete.

## Process index

| Process | Domain | Business state | Verification | Steps | Cross-domain | Ownership | Capability mapped | Evidence verified | Critical source | Critical runtime | Workflow |
| --- | --- | --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | --- |
| Diagnostic Session → Recommendation | `diagnostic` | `as-is` | `source-verified` | 7 | 0 | 7/7 | 0/7 | 7/7 | 7/7 | 0/7 | [Open workflow](../02-workflows/diagnostic-session-to-recommendation.md) |
| Property Submission → Publication | `property` | `as-is` | `source-verified` | 6 | 0 | 6/6 | 6/6 | 6/6 | 6/6 | 0/6 | [Open workflow](../02-workflows/property-submission-to-publication.md) |
| Sales Lead → Managed Case | `sales` | `as-is` | `source-verified` | 8 | 0 | 8/8 | 0/8 | 8/8 | 6/6 | 3/6 | [Open workflow](../02-workflows/sales-lead-to-managed-case.md) |
| Sales Request → Property Match | `sales` | `as-is` | `source-verified` | 5 | 1 | 5/5 | 1/5 | 5/5 | 4/4 | 1/4 | [Open workflow](../02-workflows/sales-request-to-property-match.md) |

## Verification and capability model

- `capability mapped` — the step points to a discoverable capability declared by its Domain module and therefore present in the canonical Architecture Graph capability vocabulary.
- `capability gap` — the step is real and may have runtime evidence, but the owning Domain does not yet declare a sufficiently semantic module capability for that business operation.
- `cross-domain` — the step executes in a Domain different from the process owner and is guarded by a verified `requires` contract from the process Domain to the step Domain.
- `documented` — registry topology exists, but at least one critical step is not backed by resolvable current-checkout evidence.
- `source-verified` — every critical step has at least one mapping resolved to current source/code evidence.
- `runtime-verified` — every critical step has at least one canonical runtime/contract-registry mapping. This is structural verification, not proof that a production execution trace was observed.

| Process | Owned steps | Capability mapped | Capability gaps | Cross-domain steps | Mapped steps | Evidence-verified steps | Runtime-backed steps | Critical source-verified | Critical runtime-verified |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Diagnostic Session → Recommendation | 7/7 | 0/7 | 7/7 | 0/7 | 7/7 | 7/7 | 0/7 | 7/7 | 0/7 |
| Property Submission → Publication | 6/6 | 6/6 | 0/6 | 0/6 | 6/6 | 6/6 | 0/6 | 6/6 | 0/6 |
| Sales Lead → Managed Case | 8/8 | 0/8 | 8/8 | 0/8 | 8/8 | 8/8 | 3/8 | 6/6 | 3/6 |
| Sales Request → Property Match | 5/5 | 1/5 | 4/5 | 1/5 | 5/5 | 5/5 | 1/5 | 4/4 | 1/4 |

## Diagnostic Session → Recommendation

- **Process ID:** `diagnostic.session-to-recommendation`
- **Schema:** `v4`
- **Domain:** `diagnostic`
- **Business state:** `as-is`
- **Capability coverage:** 0/7 steps
- **Cross-domain steps:** 0/7
- **Derived verification:** `source-verified`
- **Trigger:** A published methodology version is selected for a diagnostic target
- **Workflow:** [Diagnostic Session → Recommendation](../02-workflows/diagnostic-session-to-recommendation.md)

**Outcomes**

- Version-pinned diagnostic session is completed or canceled
- Evidence-backed findings and recommendations are recorded
- Recommendation decision remains traceable to the session

**Ownership, capability and runtime evidence**

| Step | Owner | Domain | Capability / gap | Kind | Critical | Executable / evidence mapping |
| --- | --- | --- | --- | --- | --- | --- |
| Draft and publish methodology version | methodology author | `diagnostic` | gap: `missing-domain-capability` | `operation` | yes | use_case `DraftDiagnosticPack` [source]<br>use_case `PublishDiagnosticPack` [source] |
| Start version-pinned session | diagnostic operator / interviewer | `diagnostic` | gap: `missing-domain-capability` | `operation` | yes | use_case `StartDiagnosticSession` [source] |
| Capture evidence | diagnostic operator / interviewer | `diagnostic` | gap: `missing-domain-capability` | `operation` | yes | use_case `CaptureDiagnosticEvidence` [source] |
| Evaluate structured evidence | deterministic evaluation engine | `diagnostic` | gap: `missing-domain-capability` | `operation` | yes | use_case `EvaluateDiagnosticSession` [source] |
| Record findings and recommendation | deterministic evaluation engine | `diagnostic` | gap: `missing-domain-capability` | `state` | yes | use_case `RecordDiagnosticResult` [source] |
| Complete coherent session | diagnostic operator / interviewer | `diagnostic` | gap: `missing-domain-capability` | `operation` | yes | use_case `CompleteDiagnosticSession` [source] |
| Review / accept recommendation | reviewer / decision maker | `diagnostic` | gap: `missing-domain-capability` | `decision` | yes | use_case `AcceptDiagnosticRecommendation` [source] |

## Property Submission → Publication

- **Process ID:** `property.submission-to-publication`
- **Schema:** `v4`
- **Domain:** `property`
- **Business state:** `as-is`
- **Capability coverage:** 6/6 steps
- **Cross-domain steps:** 0/6
- **Derived verification:** `source-verified`
- **Trigger:** External or manual property data enters the Property intake boundary
- **Workflow:** [Property Submission → Publication](../02-workflows/property-submission-to-publication.md)

**Outcomes**

- Canonical Property Asset is resolved or created
- Organization commercial state may exist as Inventory
- Market presentation may exist as Listing
- Channel-specific Publication state may be created and synchronized

**Ownership, capability and runtime evidence**

| Step | Owner | Domain | Capability / gap | Kind | Critical | Executable / evidence mapping |
| --- | --- | --- | --- | --- | --- | --- |
| Accept and validate submission | submitter / operator | `property` | `property.intake` | `operation` | yes | use_case `PropertySubmissionService` [source] |
| Resolve identity / provenance | moderator / reviewer | `property` | `property.identity.review` | `decision` | yes | use_case `PropertyModerationService` [source]<br>source `app/Domains/Property/Application/Service/PropertyIdentityWorkflowService.php` · `resolvePublished` [source] |
| Resolve or create canonical Property Asset | property manager | `property` | `property.registry` | `state` | yes | source `app/Domains/Property/Application/Service/PropertyCanonicalRuntimeService.php` · `registerAsset` [source] |
| Create or update Inventory Item | organization inventory owner | `property` | `property.inventory` | `state` | yes | source `app/Domains/Property/Application/Service/PropertyCanonicalRuntimeService.php` · `createInventory` [source] |
| Create or update Listing | listing/content operator | `property` | `property.listing` | `state` | yes | source `app/Domains/Property/Application/Service/PropertyCanonicalRuntimeService.php` · `createListing` [source] |
| Publish and synchronize channel state | publication channel adapter | `property` | `property.publish` | `outcome` | yes | source `app/Domains/Property/Application/Service/PropertyCanonicalRuntimeService.php` · `publishListing` [source] |

## Sales Lead → Managed Case

- **Process ID:** `sales.lead-to-managed-case`
- **Schema:** `v4`
- **Domain:** `sales`
- **Business state:** `as-is`
- **Capability coverage:** 0/8 steps
- **Cross-domain steps:** 0/8
- **Derived verification:** `source-verified`
- **Trigger:** Inbound public lead or external CRM webhook
- **Workflow:** [Sales Lead → Managed Case](../02-workflows/sales-lead-to-managed-case.md)

**Outcomes**

- Canonical Sales state exists
- Owner and pipeline state are managed
- Next action or outcome is recorded

**Ownership, capability and runtime evidence**

| Step | Owner | Domain | Capability / gap | Kind | Critical | Executable / evidence mapping |
| --- | --- | --- | --- | --- | --- | --- |
| Lead intake | Sales automation | `sales` | gap: `missing-domain-capability` | `operation` | yes | use_case `ReceivePublicLead` [source]<br>use_case `ReceiveCrmWebhook` [source] |
| Process durable CRM inbox | Sales automation | `sales` | gap: `missing-domain-capability` | `operation` | no | use_case `ProcessCrmInbox` [source] |
| Create or update canonical Sales state | Sales automation | `sales` | gap: `missing-domain-capability` | `state` | yes | source `app/Domains/Sales/Automation/Event/LeadCreated.php` · `sales.lead.created` [source] |
| Assign owner | manager | `sales` | gap: `missing-domain-capability` | `operation` | yes | use_case `AssignDealOwner` [source]<br>event `sales.deal.owner_assigned` [runtime] |
| Manage pipeline stage | salesperson | `sales` | gap: `missing-domain-capability` | `operation` | yes | use_case `ChangeDealStage` [source]<br>source `app/Domains/Sales/Automation/Event/DealStageChanged.php` · `sales.deal.stage_changed` [source] |
| Record activity / call | salesperson | `sales` | gap: `missing-domain-capability` | `operation` | no | use_case `CompleteSalesCall` [source]<br>source `app/Domains/Sales/Automation/Event/CallCompleted.php` · `sales.call.completed` [source] |
| Schedule next action | salesperson | `sales` | gap: `missing-domain-capability` | `operation` | yes | use_case `ScheduleDealFollowup` [source]<br>event `sales.followup.created` [runtime] |
| Record business outcome | salesperson | `sales` | gap: `missing-domain-capability` | `outcome` | yes | event `sales.deal.won` [runtime]<br>event `sales.deal.lost` [runtime] |

## Sales Request → Property Match

- **Process ID:** `sales.request-to-property-match`
- **Schema:** `v5`
- **Domain:** `sales`
- **Business state:** `as-is`
- **Capability coverage:** 1/5 steps
- **Cross-domain steps:** 1/5
- **Derived verification:** `source-verified`
- **Trigger:** Sales operator converts an inbound request into a Client Case with referenced Property context
- **Workflow:** [Sales Request → Property Match](../02-workflows/sales-request-to-property-match.md)

**Outcomes**

- Inbound request is attached to a canonical Client Case
- Referenced Property is resolved through the Property Domain contract
- Sales owns a traceable Property Match and follow-up context

**Ownership, capability and runtime evidence**

| Step | Owner | Domain | Capability / gap | Kind | Critical | Executable / evidence mapping |
| --- | --- | --- | --- | --- | --- | --- |
| Load inbound request and referenced Property context | Sales application | `sales` | gap: `missing-domain-capability` | `operation` | yes | source `app/Domains/Sales/Application/Service/SalesInboundService.php` · `createCaseFromRequest(` [source]<br>source `app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlClientCaseCommandRepository.php` · `inboundRequest(` [source] |
| Create Client Case and link inbound request | Sales application | `sales` | gap: `missing-domain-capability` | `state` | yes | source `app/Domains/Sales/Application/Service/SalesInboundService.php` · `createCaseFromRequest(` [source]<br>source `app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlClientCaseCommandRepository.php` · `createCase(` [source]<br>source `app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlClientCaseCommandRepository.php` · `attachInboundRequest(` [source] |
| Resolve canonical Property presentation | Property read boundary | `property` | `property.reference` | `operation` | yes | contract `Domains\Property\Contract\PropertyReferencePort` [runtime]<br>source `app/Domains/Sales/Infrastructure/Property/SalesPropertyReference.php` · `getPropertyPresentation(` [source] |
| Record Property Match in Sales | Sales application | `sales` | gap: `missing-domain-capability` | `state` | yes | source `app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlClientCaseCommandRepository.php` · `upsertPropertyMatch(` [source]<br>source `app/Domains/Sales/Application/Service/SalesInboundService.php` · `PropertyMatchStatus::Interested` [source] |
| Record Sales activity and publish case/lead events | Sales application | `sales` | gap: `missing-domain-capability` | `outcome` | no | source `app/Domains/Sales/Application/Service/SalesInboundService.php` · `Обʼєкт додано у підбір` [source]<br>source `app/Domains/Sales/Application/Service/SalesInboundService.php` · `ClientCaseCreated::create` [source] |

## Authority and limitations

- Registry schema `v5` extends v4 with contract-guarded cross-domain steps. Existing v4 same-domain definitions remain valid.
- A declared capability must resolve to the step Domain capability vocabulary; a missing semantic capability must be represented explicitly as `capability: null` plus `capability_gap`.
- Capability coverage is not inferred from class names, routes or permissions. It reports only canonical discoverable module capabilities.
- A cross-domain step is legal only when the process definition uses schema v5+ and the step contains a verified `contract` mapping whose current module evidence declares `role: requires` from the process Domain to the step Domain.
- Cross-domain execution does not create shared state ownership: the step capability belongs to the foreign Domain while the process remains owned by its root Domain.
- Business state (`as-is` / `to-be`) remains separate from derived runtime verification.
- Verification is never authored in process JSON. It is calculated from mappings resolved against `generate-runtime-evidence.php` and exact source symbols in the current checkout.
- `use_case` and `command` evidence is source-backed from canonical module directories.
- `event` evidence is runtime-backed from explicit Domain event catalogues; `contract` evidence is runtime-backed from canonical module cross-domain contract declarations.
- `source` mappings must resolve to an existing repository file and, when provided, contain the declared symbol.
- `runtime-verified` here means structurally backed by canonical runtime registries for every critical step. It does not mean COS observed an end-to-end production trace. Observed execution evidence belongs to a later runtime-tracing layer.
- `ProcessDiagram` renders core flow, ownership, capability and Domain projections from the same registry definition.
- Coverage ratios expose architecture/documentation completeness; they are not business performance KPIs.
