---
title: Business Process Registry
description: Generated registry of canonical COS business processes and their runtime mappings.
status: generated
updated: 2026-09-15
kind: reference
contract: reference-v1
generated: true
---

# Business Process Registry

Generated from `docs/.vitepress/processes/*.json`. Do not edit this page manually.

The registry connects human workflow documentation to executable COS references without pretending that every business step is automated.

## Process index

| Process | Domain | Truth state | Steps | Critical mapped | Workflow |
| --- | --- | --- | ---: | ---: | --- |
| Diagnostic Session → Recommendation | `diagnostic` | `as-is` | 7 | 7/7 | [Open workflow](../02-workflows/diagnostic-session-to-recommendation.md) |
| Property Submission → Publication | `property` | `as-is` | 6 | 6/6 | [Open workflow](../02-workflows/property-submission-to-publication.md) |
| Sales Lead → Managed Case | `sales` | `as-is` | 8 | 6/6 | [Open workflow](../02-workflows/sales-lead-to-managed-case.md) |

## Diagnostic Session → Recommendation

- **Process ID:** `diagnostic.session-to-recommendation`
- **Domain:** `diagnostic`
- **Truth state:** `as-is`
- **Trigger:** A published methodology version is selected for a diagnostic target
- **Workflow:** [Diagnostic Session → Recommendation](../02-workflows/diagnostic-session-to-recommendation.md)

**Outcomes**

- Version-pinned diagnostic session is completed or canceled
- Evidence-backed findings and recommendations are recorded
- Recommendation decision remains traceable to the session

**Runtime mapping**

| Step | Kind | Critical | Executable / reference mapping |
| --- | --- | --- | --- |
| Draft and publish methodology version | `operation` | yes | use_case `DraftDiagnosticPack`<br>use_case `PublishDiagnosticPack` |
| Start version-pinned session | `operation` | yes | use_case `StartDiagnosticSession` |
| Capture evidence | `operation` | yes | use_case `CaptureDiagnosticEvidence` |
| Evaluate structured evidence | `operation` | yes | use_case `EvaluateDiagnosticSession` |
| Record findings and recommendation | `state` | yes | use_case `RecordDiagnosticResult` |
| Complete coherent session | `operation` | yes | use_case `CompleteDiagnosticSession` |
| Review / accept recommendation | `decision` | yes | use_case `AcceptDiagnosticRecommendation` |

## Property Submission → Publication

- **Process ID:** `property.submission-to-publication`
- **Domain:** `property`
- **Truth state:** `as-is`
- **Trigger:** External or manual property data enters the Property intake boundary
- **Workflow:** [Property Submission → Publication](../02-workflows/property-submission-to-publication.md)

**Outcomes**

- Canonical Property Asset is resolved or created
- Organization commercial state may exist as Inventory
- Market presentation may exist as Listing
- Channel-specific Publication state may be created and synchronized

**Runtime mapping**

| Step | Kind | Critical | Executable / reference mapping |
| --- | --- | --- | --- |
| Accept and validate submission | `operation` | yes | use_case `PropertySubmissionService` |
| Resolve identity / provenance | `decision` | yes | use_case `PropertyModerationService`<br>source `app/Domains/Property/Application/Service/PropertyIdentityWorkflowService.php` · `resolvePublished` |
| Resolve or create canonical Property Asset | `state` | yes | source `app/Domains/Property/Application/Service/PropertyCanonicalRuntimeService.php` · `registerAsset` |
| Create or update Inventory Item | `state` | yes | source `app/Domains/Property/Application/Service/PropertyCanonicalRuntimeService.php` · `createInventory` |
| Create or update Listing | `state` | yes | source `app/Domains/Property/Application/Service/PropertyCanonicalRuntimeService.php` · `createListing` |
| Publish and synchronize channel state | `outcome` | yes | source `app/Domains/Property/Application/Service/PropertyCanonicalRuntimeService.php` · `publishListing` |

## Sales Lead → Managed Case

- **Process ID:** `sales.lead-to-managed-case`
- **Domain:** `sales`
- **Truth state:** `as-is`
- **Trigger:** Inbound public lead or external CRM webhook
- **Workflow:** [Sales Lead → Managed Case](../02-workflows/sales-lead-to-managed-case.md)

**Outcomes**

- Canonical Sales state exists
- Owner and pipeline state are managed
- Next action or outcome is recorded

**Runtime mapping**

| Step | Kind | Critical | Executable / reference mapping |
| --- | --- | --- | --- |
| Lead intake | `operation` | yes | use_case `ReceivePublicLead`<br>use_case `ReceiveCrmWebhook` |
| Process durable CRM inbox | `operation` | no | use_case `ProcessCrmInbox` |
| Create or update canonical Sales state | `state` | yes | event `sales.lead.created` |
| Assign owner | `operation` | yes | use_case `AssignDealOwner`<br>event `sales.deal.owner_assigned` |
| Manage pipeline stage | `operation` | yes | use_case `ChangeDealStage`<br>event `sales.deal.stage_changed` |
| Record activity / call | `operation` | no | use_case `CompleteSalesCall`<br>event `sales.call.completed` |
| Schedule next action | `operation` | yes | use_case `ScheduleDealFollowup`<br>event `sales.followup.created` |
| Record business outcome | `outcome` | yes | event `sales.deal.won`<br>event `sales.deal.lost` |

## Authority and limitations

- Registry structure and mappings are machine-checked by `check-processes.mjs`.
- `use_case`, `command` and `event` mappings must resolve to generated reference from the same checkout.
- `source` mappings must resolve to an existing repository file and, when provided, contain the declared symbol.
- `as-is` means the process is real, not that every step is machine-enforced.
- `runtime-verified` requires every critical step to have an explicit runtime mapping.
- Mermaid diagrams remain the human visual projection on the workflow page; the Process Registry is the structured documentation contract.
