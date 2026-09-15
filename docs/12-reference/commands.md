---
title: Command DTO Reference
description: Generated index of explicit module-owned Application DTO command contracts.
status: generated
kind: reference
generated: true
---

<!-- GENERATED FILE: DO NOT EDIT MANUALLY. Run `npm run docs:generate`. -->

# Command DTO Reference

> Джерело істини: `app/Domains/*/Application/DTO/*Command.php` для зареєстрованих module manifests.

Цей catalogue документує тільки explicit command DTO contracts. Він навмисно не класифікує service methods, repositories або UseCase classes як Commands за назвою чи поведінкою.

## Summary

| Module | Commands |
| --- | ---: |
| `diagnostic` | 1 |
| `property` | 4 |
| `sales` | 6 |

## Diagnostics (`diagnostic`)

| Command | Source |
| --- | --- |
| `StartDiagnosticSessionCommand` | `app/Domains/Diagnostic/Application/DTO/StartDiagnosticSessionCommand.php` |

## Property (`property`)

| Command | Source |
| --- | --- |
| `ChangePropertyLifecycleCommand` | `app/Domains/Property/Application/DTO/ChangePropertyLifecycleCommand.php` |
| `ReclassifyPropertyAssetCommand` | `app/Domains/Property/Application/DTO/ReclassifyPropertyAssetCommand.php` |
| `RegisterPropertyAssetCommand` | `app/Domains/Property/Application/DTO/RegisterPropertyAssetCommand.php` |
| `RelocatePropertyAssetCommand` | `app/Domains/Property/Application/DTO/RelocatePropertyAssetCommand.php` |

## Sales (`sales`)

| Command | Source |
| --- | --- |
| `ChangeDealStageCommand` | `app/Domains/Sales/Application/DTO/ChangeDealStageCommand.php` |
| `CreateTaskCommand` | `app/Domains/Sales/Application/DTO/CreateTaskCommand.php` |
| `RecordActionOutcomeCommand` | `app/Domains/Sales/Application/DTO/RecordActionOutcomeCommand.php` |
| `RecordCompletedCallCommand` | `app/Domains/Sales/Application/DTO/RecordCompletedCallCommand.php` |
| `ScheduleFollowupCommand` | `app/Domains/Sales/Application/DTO/ScheduleFollowupCommand.php` |
| `SendMessageCommand` | `app/Domains/Sales/Application/DTO/SendMessageCommand.php` |

## Classification boundary

Назви на кшталт `ClientCaseCommandService` або `ClientCaseCommandRepositoryInterface` не потрапляють сюди: це services/contracts, а не command message DTO. Так само `Application/UseCase` документується окремим generated reference. Якщо COS пізніше введе typed Kernel Command contract, цей catalogue треба переключити на нього як на сильніший source of truth.
