---
title: Довідник помилок і відмов
description: Згенерований каталог канонічних видів execution failure і явних classified failure implementations.
status: generated
kind: reference
generated: true
---

<!-- ЗГЕНЕРОВАНИЙ ФАЙЛ: НЕ РЕДАГУВАТИ ВРУЧНУ. Запустіть `npm run docs:generate`. -->

# Довідник помилок і відмов

> Джерело істини: `ExecutionFailureKind` + PHP-класи, які явно реалізують classified failure contract.

## Види відмов

| Вид | Можна повторити |
| --- | --- |
| `RETRYABLE` | так |
| `PERMANENT` | ні |
| `CONCURRENCY_CONFLICT` | так |
| `POLICY_DENIED` | ні |
| `BUDGET_EXCEEDED` | ні |
| `EXTERNAL_UNAVAILABLE` | так |

## Класифіковані реалізації

| Символ | Джерело |
| --- | --- |
| `ExecutionFailureException` | `app/Kernel/Execution/ExecutionFailureException.php` |
| `LlmBudgetExceededException` | `app/Kernel/Llm/LlmBudgetExceededException.php` |
| `LlmProviderException` | `app/Kernel/Llm/LlmProviderException.php` |
| `StaleActionExecutionClaimException` | `app/Kernel/Action/StaleActionExecutionClaimException.php` |
