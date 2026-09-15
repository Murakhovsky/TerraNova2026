---
title: Business Process Registry
description: Generated registry of canonical COS business processes, ownership and runtime coverage.
status: generated
updated: 2026-09-15
kind: reference
contract: reference-v1
generated: true
---

# Business Process Registry

Generated from `docs/.vitepress/processes/*.json`. Do not edit this page manually.

The registry connects human workflow documentation to process ownership and executable COS references without pretending that every business step is automated.

## Process index

| Process | Domain | Truth state | Steps | Ownership | Runtime mapped | Critical mapped | Workflow |
| --- | --- | --- | ---: | ---: | ---: | ---: | --- |
| Diagnostic Session → Recommendation | `diagnostic` | `as-is` | 7 | 7/7 | 7/7 | 7/7 | [Open workflow](../02-workflows/diagnostic-session-to-recommendation.md) |
| Property Submission → Publication | `property` | `as-is` | 6 | 6/6 | 6/6 | 6/6 | [Open workflow](../02-workflows/property-submission-to-publication.md) |
| Sales Lead → Managed Case | `sales` | `as-is` | 8 | 8/8 | 8/8 | 6/6 | [Open workflow](../02-workflows/sales-lead-to-managed-case.md) |

## Coverage

Coverage is structural, not a quality score. `owned` means a responsible actor is declared; `runtime mapped` means at least one executable/reference mapping exists; `critical mapped` is the minimum requirement for `runtime-verified`.

| Process | Owned steps | Runtime-mapped steps | Runtime-mapped critical steps |
| --- | ---: | ---: | ---: |
| Diagnostic Session → Recommendation | 7/7 | 7/7 | 7/7 |
| Property Submission → Publication | 6/6 | 6/6 | 6/6 |
| Sales Lead → Managed Case | 8/8 | 8/8 | 6/6 |

## Diagnostic Session → Recommendation

- **Process ID:** `diagnostic.session-to-recommendation`
- **Schema:** `v2`
- **Domain:** `diagnostic`
- **Truth state:** `as-is`
- **Trigger:** A published methodology version is selected for a diagnostic target
- **Workflow:** [Diagnostic Session → Recommendation](../02-workflows/diagnostic-session-to-recommendation.md)

**Outcomes**

- Version-pinned diagnostic session is completed or canceled
- Evidence-backed findings and recommendations are recorded
- Recommendation decision remains traceable to the session

**Ownership and runtime mapping**

| Step | Owner | Kind | Critical | Executable / reference mapping |
| --- | --- | --- | --- | --- |
| Draft and publish methodology version | methodology author | `operation` | yes | use_case `DraftDiagnosticPack`<br>use_case `PublishDiagnosticPack` |
| Start version-pinned session | diagnostic operator / interviewer | `operation` | yes | use_case `StartDiagnosticSession` |
| Capture evidence | diagnostic operator / interviewer | `operation` | yes | use_case `CaptureDiagnosticEvidence` |
| Evaluate structured evidence | deterministic evaluation engine | `operation` | yes | use_case `EvaluateDiagnosticSession` |
| Record findings and recommendation | deterministic evaluation engine | `state` | yes | use_case `RecordDiagnosticResult` |
| Complete coherent session | diagnostic operator / interviewer | `operation` | yes | use_case `CompleteDiagnosticSession` |
| Review / accept recommendation | reviewer / decision maker | `decision` | yes | use_case `AcceptDiagnosticRecommendation` |

## Property Submission → Publication

- **Process ID:** `property.submission-to-publication`
- **Schema:** `v2`
- **Domain:** `property`
- **Truth state:** `as-is`
- **Trigger:** External or manual property data enters the Property intake boundary
- **Workflow:** [Property Submission → Publication](../02-workflows/property-submission-to-publication.md)

**Outcomes**

- Canonical Property Asset is resolved or created
- Organization commercial state may exist as Inventory
- Market presentation may exist as Listing
- Channel-specific Publication state may be created and synchronized

**Ownership and runtime mapping**

| Step | Owner | Kind | Critical | Executable / reference mapping |
| --- | --- | --- | --- | --- |
| Accept and validate submission | submitter / operator | `operation` | yes | use_case `PropertySubmissionService` |
| Resolve identity / provenance | moderator / reviewer | `decision` | yes | use_case `PropertyModerationService`<br>source `app/Domains/Property/Application/Service/PropertyIdentityWorkflowService.php` · `resolvePublished` |
| Resolve or create canonical Property Asset | property manager | `state` | yes | source `app/Domains/Property/Application/Service/PropertyCanonicalRuntimeService.php` · `registerAsset` |
| Create or update Inventory Item | organization inventory owner | `state` | yes | source `app/Domains/Property/Application/Service/PropertyCanonicalRuntimeService.php` · `createInventory` |
| Create or update Listing | listing/content operator | `state` | yes | source `app/Domains/Property/Application/Service/PropertyCanonicalRuntimeService.php` · `createListing` |
| Publish and synchronize channel state | publication channel adapter | `outcome` | yes | source `app/Domains/Property/Application/Service/PropertyCanonicalRuntimeService.php` · `publishListing` |

## Sales Lead → Managed Case

- **Process ID:** `sales.lead-to-managed-case`
- **Schema:** `v2`
- **Domain:** `sales`
- **Truth state:** `as-is`
- **Trigger:** Inbound public lead or external CRM webhook
- **Workflow:** [Sales Lead → Managed Case](../02-workflows/sales-lead-to-managed-case.md)

**Outcomes**

- Canonical Sales state exists
- Owner and pipeline state are managed
- Next action or outcome is recorded

**Ownership and runtime mapping**

| Step | Owner | Kind | Critical | Executable / reference mapping |
| --- | --- | --- | --- | --- |
| Lead intake | Sales automation | `operation` | yes | use_case `ReceivePublicLead`<br>use_case `ReceiveCrmWebhook` |
| Process durable CRM inbox | Sales automation | `operation` | no | use_case `ProcessCrmInbox` |
| Create or update canonical Sales state | Sales automation | `state` | yes | source `app/Domains/Sales/Automation/Event/LeadCreated.php` · `sales.lead.created` |
| Assign owner | manager | `operation` | yes | use_case `AssignDealOwner`<br>event `sales.deal.owner_assigned` |
| Manage pipeline stage | salesperson | `operation` | yes | use_case `ChangeDealStage`<br>source `app/Domains/Sales/Automation/Event/DealStageChanged.php` · `sales.deal.stage_changed` |
| Record activity / call | salesperson | `operation` | no | use_case `CompleteSalesCall`<br>source `app/Domains/Sales/Automation/Event/CallCompleted.php` · `sales.call.completed` |
| Schedule next action | salesperson | `operation` | yes | use_case `ScheduleDealFollowup`<br>event `sales.followup.created` |
| Record business outcome | salesperson | `outcome` | yes | event `sales.deal.won`<br>event `sales.deal.lost` |

## Authority and limitations

- Registry schema `v2` requires every process step to declare exactly one responsible `owner` from the process `actors` list.
- Registry structure, topology, ownership and mappings are machine-checked by `check-processes.mjs`.
- `use_case`, `command` and `event` mappings must resolve to generated reference from the same checkout.
- `source` mappings must resolve to an existing repository file and, when provided, contain the declared symbol.
- `as-is` means the process is real, not that every step is machine-enforced.
- `runtime-verified` requires every critical step to have an explicit runtime mapping.
- `ProcessDiagram` renders both core flow and ownership projection from the same registry definition.
- Coverage ratios expose documentation completeness; they are not business performance KPIs.
