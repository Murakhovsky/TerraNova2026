---
title: Business Process Registry
description: Generated registry of canonical COS business processes, ownership and evidence-backed runtime verification.
status: generated
updated: 2026-09-15
kind: reference
contract: reference-v1
generated: true
---

# Business Process Registry

Generated from `docs/.vitepress/processes/*.json` and the current-checkout runtime evidence catalogue. Do not edit this page manually.

Business state and verification are separate dimensions: `as-is` / `to-be` describes the process itself; `documented` / `source-verified` / `runtime-verified` describes how strongly its critical steps are backed by current code and canonical runtime registries.

## Process index

| Process | Domain | Business state | Verification | Steps | Ownership | Evidence verified | Critical source | Critical runtime | Workflow |
| --- | --- | --- | --- | ---: | ---: | ---: | ---: | ---: | --- |
| Diagnostic Session → Recommendation | `diagnostic` | `as-is` | `source-verified` | 7 | 7/7 | 7/7 | 7/7 | 0/7 | [Open workflow](../02-workflows/diagnostic-session-to-recommendation.md) |
| Property Submission → Publication | `property` | `as-is` | `source-verified` | 6 | 6/6 | 6/6 | 6/6 | 0/6 | [Open workflow](../02-workflows/property-submission-to-publication.md) |
| Sales Lead → Managed Case | `sales` | `as-is` | `source-verified` | 8 | 8/8 | 8/8 | 6/6 | 3/6 | [Open workflow](../02-workflows/sales-lead-to-managed-case.md) |

## Verification model

- `documented` — registry topology exists, but at least one critical step is not backed by resolvable current-checkout evidence.
- `source-verified` — every critical step has at least one mapping resolved to current source/code evidence.
- `runtime-verified` — every critical step has at least one canonical runtime/contract-registry mapping. This is structural verification, not proof that a production execution trace was observed.

| Process | Owned steps | Mapped steps | Evidence-verified steps | Runtime-backed steps | Critical source-verified | Critical runtime-verified |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Diagnostic Session → Recommendation | 7/7 | 7/7 | 7/7 | 0/7 | 7/7 | 0/7 |
| Property Submission → Publication | 6/6 | 6/6 | 6/6 | 0/6 | 6/6 | 0/6 |
| Sales Lead → Managed Case | 8/8 | 8/8 | 8/8 | 3/8 | 6/6 | 3/6 |

## Diagnostic Session → Recommendation

- **Process ID:** `diagnostic.session-to-recommendation`
- **Schema:** `v3`
- **Domain:** `diagnostic`
- **Business state:** `as-is`
- **Derived verification:** `source-verified`
- **Trigger:** A published methodology version is selected for a diagnostic target
- **Workflow:** [Diagnostic Session → Recommendation](../02-workflows/diagnostic-session-to-recommendation.md)

**Outcomes**

- Version-pinned diagnostic session is completed or canceled
- Evidence-backed findings and recommendations are recorded
- Recommendation decision remains traceable to the session

**Ownership and runtime evidence**

| Step | Owner | Kind | Critical | Executable / evidence mapping |
| --- | --- | --- | --- | --- |
| Draft and publish methodology version | methodology author | `operation` | yes | use_case `DraftDiagnosticPack` [source]<br>use_case `PublishDiagnosticPack` [source] |
| Start version-pinned session | diagnostic operator / interviewer | `operation` | yes | use_case `StartDiagnosticSession` [source] |
| Capture evidence | diagnostic operator / interviewer | `operation` | yes | use_case `CaptureDiagnosticEvidence` [source] |
| Evaluate structured evidence | deterministic evaluation engine | `operation` | yes | use_case `EvaluateDiagnosticSession` [source] |
| Record findings and recommendation | deterministic evaluation engine | `state` | yes | use_case `RecordDiagnosticResult` [source] |
| Complete coherent session | diagnostic operator / interviewer | `operation` | yes | use_case `CompleteDiagnosticSession` [source] |
| Review / accept recommendation | reviewer / decision maker | `decision` | yes | use_case `AcceptDiagnosticRecommendation` [source] |

## Property Submission → Publication

- **Process ID:** `property.submission-to-publication`
- **Schema:** `v3`
- **Domain:** `property`
- **Business state:** `as-is`
- **Derived verification:** `source-verified`
- **Trigger:** External or manual property data enters the Property intake boundary
- **Workflow:** [Property Submission → Publication](../02-workflows/property-submission-to-publication.md)

**Outcomes**

- Canonical Property Asset is resolved or created
- Organization commercial state may exist as Inventory
- Market presentation may exist as Listing
- Channel-specific Publication state may be created and synchronized

**Ownership and runtime evidence**

| Step | Owner | Kind | Critical | Executable / evidence mapping |
| --- | --- | --- | --- | --- |
| Accept and validate submission | submitter / operator | `operation` | yes | use_case `PropertySubmissionService` [source] |
| Resolve identity / provenance | moderator / reviewer | `decision` | yes | use_case `PropertyModerationService` [source]<br>source `app/Domains/Property/Application/Service/PropertyIdentityWorkflowService.php` · `resolvePublished` [source] |
| Resolve or create canonical Property Asset | property manager | `state` | yes | source `app/Domains/Property/Application/Service/PropertyCanonicalRuntimeService.php` · `registerAsset` [source] |
| Create or update Inventory Item | organization inventory owner | `state` | yes | source `app/Domains/Property/Application/Service/PropertyCanonicalRuntimeService.php` · `createInventory` [source] |
| Create or update Listing | listing/content operator | `state` | yes | source `app/Domains/Property/Application/Service/PropertyCanonicalRuntimeService.php` · `createListing` [source] |
| Publish and synchronize channel state | publication channel adapter | `outcome` | yes | source `app/Domains/Property/Application/Service/PropertyCanonicalRuntimeService.php` · `publishListing` [source] |

## Sales Lead → Managed Case

- **Process ID:** `sales.lead-to-managed-case`
- **Schema:** `v3`
- **Domain:** `sales`
- **Business state:** `as-is`
- **Derived verification:** `source-verified`
- **Trigger:** Inbound public lead or external CRM webhook
- **Workflow:** [Sales Lead → Managed Case](../02-workflows/sales-lead-to-managed-case.md)

**Outcomes**

- Canonical Sales state exists
- Owner and pipeline state are managed
- Next action or outcome is recorded

**Ownership and runtime evidence**

| Step | Owner | Kind | Critical | Executable / evidence mapping |
| --- | --- | --- | --- | --- |
| Lead intake | Sales automation | `operation` | yes | use_case `ReceivePublicLead` [source]<br>use_case `ReceiveCrmWebhook` [source] |
| Process durable CRM inbox | Sales automation | `operation` | no | use_case `ProcessCrmInbox` [source] |
| Create or update canonical Sales state | Sales automation | `state` | yes | source `app/Domains/Sales/Automation/Event/LeadCreated.php` · `sales.lead.created` [source] |
| Assign owner | manager | `operation` | yes | use_case `AssignDealOwner` [source]<br>event `sales.deal.owner_assigned` [runtime] |
| Manage pipeline stage | salesperson | `operation` | yes | use_case `ChangeDealStage` [source]<br>source `app/Domains/Sales/Automation/Event/DealStageChanged.php` · `sales.deal.stage_changed` [source] |
| Record activity / call | salesperson | `operation` | no | use_case `CompleteSalesCall` [source]<br>source `app/Domains/Sales/Automation/Event/CallCompleted.php` · `sales.call.completed` [source] |
| Schedule next action | salesperson | `operation` | yes | use_case `ScheduleDealFollowup` [source]<br>event `sales.followup.created` [runtime] |
| Record business outcome | salesperson | `outcome` | yes | event `sales.deal.won` [runtime]<br>event `sales.deal.lost` [runtime] |

## Authority and limitations

- Registry schema `v3` keeps business state (`as-is` / `to-be`) separate from derived verification.
- Verification is never authored in process JSON. It is calculated from mappings resolved against `generate-runtime-evidence.php` and exact source symbols in the current checkout.
- `use_case` and `command` evidence is source-backed from canonical module directories.
- `event` evidence is runtime-backed from explicit Domain event catalogues; `contract` evidence is runtime-backed from canonical module cross-domain contract declarations.
- `source` mappings must resolve to an existing repository file and, when provided, contain the declared symbol.
- `runtime-verified` here means structurally backed by canonical runtime registries for every critical step. It does not mean COS observed an end-to-end production trace. Observed execution evidence belongs to a later runtime-tracing layer.
- `ProcessDiagram` renders core flow and ownership projections from the same registry definition. Sequence and entity lifecycle diagrams are not inferred from generic steps because the registry does not yet carry those semantics.
- Coverage ratios expose documentation/evidence completeness; they are not business performance KPIs.
