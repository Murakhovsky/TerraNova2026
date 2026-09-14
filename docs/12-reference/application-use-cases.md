---
title: Application Use Cases
description: Generated index of module-owned Application/UseCase entry points.
status: generated
kind: reference
generated: true
---

<!-- GENERATED FILE: DO NOT EDIT MANUALLY. Run `npm run docs:generate`. -->

# Application Use Cases

> Джерело істини: `app/Domains/*/Application/UseCase/*.php` для зареєстрованих module manifests.

Цей індекс показує application entry points за чинною directory convention. Він не стверджує, що кожен клас є окремою Kernel Command і не намагається вгадувати семантику методів усередині service classes.

## Summary

| Module | Entry points |
| --- | ---: |
| `diagnostic` | 10 |
| `property` | 2 |
| `sales` | 7 |

## Diagnostics (`diagnostic`)

| Symbol | Source |
| --- | --- |
| `AcceptDiagnosticRecommendation` | `app/Domains/Diagnostic/Application/UseCase/AcceptDiagnosticRecommendation.php` |
| `CancelDiagnosticSession` | `app/Domains/Diagnostic/Application/UseCase/CancelDiagnosticSession.php` |
| `CaptureDiagnosticEvidence` | `app/Domains/Diagnostic/Application/UseCase/CaptureDiagnosticEvidence.php` |
| `CompleteDiagnosticSession` | `app/Domains/Diagnostic/Application/UseCase/CompleteDiagnosticSession.php` |
| `DraftDiagnosticPack` | `app/Domains/Diagnostic/Application/UseCase/DraftDiagnosticPack.php` |
| `EvaluateDiagnosticSession` | `app/Domains/Diagnostic/Application/UseCase/EvaluateDiagnosticSession.php` |
| `PublishDiagnosticPack` | `app/Domains/Diagnostic/Application/UseCase/PublishDiagnosticPack.php` |
| `RecordDiagnosticResult` | `app/Domains/Diagnostic/Application/UseCase/RecordDiagnosticResult.php` |
| `ReviseDiagnosticPack` | `app/Domains/Diagnostic/Application/UseCase/ReviseDiagnosticPack.php` |
| `StartDiagnosticSession` | `app/Domains/Diagnostic/Application/UseCase/StartDiagnosticSession.php` |

## Property (`property`)

| Symbol | Source |
| --- | --- |
| `PropertyModerationService` | `app/Domains/Property/Application/UseCase/PropertyModerationService.php` |
| `PropertySubmissionService` | `app/Domains/Property/Application/UseCase/PropertySubmissionService.php` |

## Sales (`sales`)

| Symbol | Source |
| --- | --- |
| `AssignDealOwner` | `app/Domains/Sales/Application/UseCase/AssignDealOwner.php` |
| `ChangeDealStage` | `app/Domains/Sales/Application/UseCase/ChangeDealStage.php` |
| `CompleteSalesCall` | `app/Domains/Sales/Application/UseCase/CompleteSalesCall.php` |
| `ProcessCrmInbox` | `app/Domains/Sales/Application/UseCase/ProcessCrmInbox.php` |
| `ReceiveCrmWebhook` | `app/Domains/Sales/Application/UseCase/ReceiveCrmWebhook.php` |
| `ReceivePublicLead` | `app/Domains/Sales/Application/UseCase/ReceivePublicLead.php` |
| `ScheduleDealFollowup` | `app/Domains/Sales/Application/UseCase/ScheduleDealFollowup.php` |

## Scope

Reference навмисно прив’язаний до явної `Application/UseCase` convention. Command services, handlers або operations, що живуть поза цією convention, мають отримати окремий explicit catalogue замість широкого regex-сканування PHP.
