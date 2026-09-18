---
title: Довідник Command DTO
description: Згенерований індекс явних Application DTO command-контрактів, якими володіють модулі.
status: generated
kind: reference
generated: true
---

<!-- ЗГЕНЕРОВАНИЙ ФАЙЛ: НЕ РЕДАГУВАТИ ВРУЧНУ. Запустіть `npm run docs:generate`. -->

# Довідник Command DTO

> Джерело істини: `app/Domains/*/Application/DTO/*Command.php` для зареєстрованих module manifests.

Цей каталог документує лише явні command DTO contracts. Він навмисно не класифікує service methods, repositories або UseCase classes як Commands за назвою чи поведінкою.

## Підсумок

| Модуль | Commands |
| --- | ---: |
| `construction` | 0 |
| `diagnostic` | 1 |
| `finance` | 0 |
| `hr` | 0 |
| `procurement` | 0 |
| `property` | 4 |
| `real_estate` | 0 |
| `sales` | 7 |
| `service` | 0 |

## Construction (`construction`)

Явних `*Command` DTO contracts не знайдено.

## Diagnostics (`diagnostic`)

| Command | Джерело |
| --- | --- |
| `StartDiagnosticSessionCommand` | `app/Domains/Diagnostic/Application/DTO/StartDiagnosticSessionCommand.php` |

## Finance (`finance`)

Явних `*Command` DTO contracts не знайдено.

## HR (`hr`)

Явних `*Command` DTO contracts не знайдено.

## Procurement (`procurement`)

Явних `*Command` DTO contracts не знайдено.

## Property (`property`)

| Command | Джерело |
| --- | --- |
| `ChangePropertyLifecycleCommand` | `app/Domains/Property/Application/DTO/ChangePropertyLifecycleCommand.php` |
| `ReclassifyPropertyAssetCommand` | `app/Domains/Property/Application/DTO/ReclassifyPropertyAssetCommand.php` |
| `RegisterPropertyAssetCommand` | `app/Domains/Property/Application/DTO/RegisterPropertyAssetCommand.php` |
| `RelocatePropertyAssetCommand` | `app/Domains/Property/Application/DTO/RelocatePropertyAssetCommand.php` |

## Real Estate (`real_estate`)

Явних `*Command` DTO contracts не знайдено.

## Sales (`sales`)

| Command | Джерело |
| --- | --- |
| `ChangeDealStageCommand` | `app/Domains/Sales/Application/DTO/ChangeDealStageCommand.php` |
| `CreateTaskCommand` | `app/Domains/Sales/Application/DTO/CreateTaskCommand.php` |
| `RecordActionOutcomeCommand` | `app/Domains/Sales/Application/DTO/RecordActionOutcomeCommand.php` |
| `RecordCompletedCallCommand` | `app/Domains/Sales/Application/DTO/RecordCompletedCallCommand.php` |
| `ScheduleFollowupCommand` | `app/Domains/Sales/Application/DTO/ScheduleFollowupCommand.php` |
| `ScheduleLeadFollowupCommand` | `app/Domains/Sales/Application/DTO/ScheduleLeadFollowupCommand.php` |
| `SendMessageCommand` | `app/Domains/Sales/Application/DTO/SendMessageCommand.php` |

## Service (`service`)

Явних `*Command` DTO contracts не знайдено.

## Межа класифікації

Назви на кшталт `ClientCaseCommandService` або `ClientCaseCommandRepositoryInterface` не потрапляють сюди: це services/contracts, а не command message DTO. Так само `Application/UseCase` документується окремим згенерованим довідником. Якщо COS пізніше введе typed Kernel Command contract, цей каталог треба переключити на нього як на сильніше джерело істини.
