---
title: Сценарії використання Application
description: Згенерований індекс module-owned точок входу Application/UseCase.
status: generated
kind: reference
generated: true
---

<!-- ЗГЕНЕРОВАНИЙ ФАЙЛ: НЕ РЕДАГУВАТИ ВРУЧНУ. Запустіть `npm run docs:generate`. -->

# Сценарії використання Application

> Джерело істини: `app/Domains/*/Application/UseCase/*.php` у поточному checkout.

## Підсумок

| Модуль | Точок входу |
| --- | ---: |
| `diagnostic` | 10 |
| `finance` | 0 |
| `procurement` | 0 |
| `property` | 2 |
| `sales` | 7 |
| `service` | 0 |

## Diagnostics (`diagnostic`)

| Символ | Джерело |
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

## Finance (`finance`)

Точок входу Application UseCase не знайдено.

## Procurement (`procurement`)

Точок входу Application UseCase не знайдено.

## Property (`property`)

| Символ | Джерело |
| --- | --- |
| `PropertyModerationService` | `app/Domains/Property/Application/UseCase/PropertyModerationService.php` |
| `PropertySubmissionService` | `app/Domains/Property/Application/UseCase/PropertySubmissionService.php` |

## Sales (`sales`)

| Символ | Джерело |
| --- | --- |
| `AssignDealOwner` | `app/Domains/Sales/Application/UseCase/AssignDealOwner.php` |
| `ChangeDealStage` | `app/Domains/Sales/Application/UseCase/ChangeDealStage.php` |
| `CompleteSalesCall` | `app/Domains/Sales/Application/UseCase/CompleteSalesCall.php` |
| `ProcessCrmInbox` | `app/Domains/Sales/Application/UseCase/ProcessCrmInbox.php` |
| `ReceiveCrmWebhook` | `app/Domains/Sales/Application/UseCase/ReceiveCrmWebhook.php` |
| `ReceivePublicLead` | `app/Domains/Sales/Application/UseCase/ReceivePublicLead.php` |
| `ScheduleDealFollowup` | `app/Domains/Sales/Application/UseCase/ScheduleDealFollowup.php` |

## Service (`service`)

Точок входу Application UseCase не знайдено.
