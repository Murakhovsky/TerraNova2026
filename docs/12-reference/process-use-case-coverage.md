---
title: Process use-case coverage
description: Generated coverage of installable Domain Application Use Cases by canonical Process Registry steps.
status: generated
updated: 2026-09-17
kind: reference
contract: reference-v1
generated: true
---

# Process use-case coverage

> Authority: current-checkout `app/Domains/*/Application/UseCase/*.php`, installable module manifests, Process Registry mappings and explicit exemptions.

This reference answers a different question from Domain Process Coverage: not merely whether a Domain has a process model, but whether its executable Application entry points are represented in canonical business-process semantics.

## Summary

- **Application Use Cases:** 19
- **Mapped to canonical process steps:** 19
- **Explicit exemptions:** 0
- **Uncovered:** 0
- **Coverage satisfied:** 19/19

| Domain | Use Cases | Mapped | Exempt | Uncovered |
| --- | ---: | ---: | ---: | ---: |
| `diagnostic` | 10 | 10 | 0 | 0 |
| `property` | 2 | 2 | 0 | 0 |
| `sales` | 7 | 7 | 0 | 0 |

## Application entry points

| Domain | Use Case | Status | Canonical process step | Source |
| --- | --- | --- | --- | --- |
| `diagnostic` | `AcceptDiagnosticRecommendation` | `mapped` | `diagnostic.session-to-recommendation:decision` | `app/Domains/Diagnostic/Application/UseCase/AcceptDiagnosticRecommendation.php` |
| `diagnostic` | `CancelDiagnosticSession` | `mapped` | `diagnostic.session-to-recommendation:cancelled` | `app/Domains/Diagnostic/Application/UseCase/CancelDiagnosticSession.php` |
| `diagnostic` | `CaptureDiagnosticEvidence` | `mapped` | `diagnostic.session-to-recommendation:evidence` | `app/Domains/Diagnostic/Application/UseCase/CaptureDiagnosticEvidence.php` |
| `diagnostic` | `CompleteDiagnosticSession` | `mapped` | `diagnostic.session-to-recommendation:complete` | `app/Domains/Diagnostic/Application/UseCase/CompleteDiagnosticSession.php` |
| `diagnostic` | `DraftDiagnosticPack` | `mapped` | `diagnostic.session-to-recommendation:methodology` | `app/Domains/Diagnostic/Application/UseCase/DraftDiagnosticPack.php` |
| `diagnostic` | `EvaluateDiagnosticSession` | `mapped` | `diagnostic.session-to-recommendation:evaluation` | `app/Domains/Diagnostic/Application/UseCase/EvaluateDiagnosticSession.php` |
| `diagnostic` | `PublishDiagnosticPack` | `mapped` | `diagnostic.session-to-recommendation:methodology` | `app/Domains/Diagnostic/Application/UseCase/PublishDiagnosticPack.php` |
| `diagnostic` | `RecordDiagnosticResult` | `mapped` | `diagnostic.session-to-recommendation:result` | `app/Domains/Diagnostic/Application/UseCase/RecordDiagnosticResult.php` |
| `diagnostic` | `ReviseDiagnosticPack` | `mapped` | `diagnostic.session-to-recommendation:revision` | `app/Domains/Diagnostic/Application/UseCase/ReviseDiagnosticPack.php` |
| `diagnostic` | `StartDiagnosticSession` | `mapped` | `diagnostic.session-to-recommendation:session` | `app/Domains/Diagnostic/Application/UseCase/StartDiagnosticSession.php` |
| `property` | `PropertyModerationService` | `mapped` | `property.submission-to-publication:identity` | `app/Domains/Property/Application/UseCase/PropertyModerationService.php` |
| `property` | `PropertySubmissionService` | `mapped` | `property.submission-to-publication:submission` | `app/Domains/Property/Application/UseCase/PropertySubmissionService.php` |
| `sales` | `AssignDealOwner` | `mapped` | `sales.lead-to-managed-case:assign-owner` | `app/Domains/Sales/Application/UseCase/AssignDealOwner.php` |
| `sales` | `ChangeDealStage` | `mapped` | `sales.lead-to-managed-case:pipeline` | `app/Domains/Sales/Application/UseCase/ChangeDealStage.php` |
| `sales` | `CompleteSalesCall` | `mapped` | `sales.lead-to-managed-case:activity` | `app/Domains/Sales/Application/UseCase/CompleteSalesCall.php` |
| `sales` | `ProcessCrmInbox` | `mapped` | `sales.lead-to-managed-case:crm-inbox` | `app/Domains/Sales/Application/UseCase/ProcessCrmInbox.php` |
| `sales` | `ReceiveCrmWebhook` | `mapped` | `sales.lead-to-managed-case:intake` | `app/Domains/Sales/Application/UseCase/ReceiveCrmWebhook.php` |
| `sales` | `ReceivePublicLead` | `mapped` | `sales.lead-to-managed-case:intake` | `app/Domains/Sales/Application/UseCase/ReceivePublicLead.php` |
| `sales` | `ScheduleDealFollowup` | `mapped` | `sales.lead-to-managed-case:followup` | `app/Domains/Sales/Application/UseCase/ScheduleDealFollowup.php` |

## Explicit exemptions

No active exemptions.

## Coverage contract

1. Only Application Use Cases owned by installable Domains are included.
2. A use case is mapped when at least one Process Registry step references it with `runtime.type = use_case`.
3. An executable use case that is intentionally not a business-process step requires an explicit exemption with an owner and reason.
4. Stale exemptions are invalid and fail CI.
5. Coverage does not mean the use case is runtime-observed in production; runtime evidence strength remains a separate dimension.
