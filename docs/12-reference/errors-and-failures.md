---
title: Errors & Failures Reference
description: Generated catalogue of canonical execution failure kinds and explicit classified failure implementations.
status: generated
kind: reference
generated: true
---

<!-- GENERATED FILE: DO NOT EDIT MANUALLY. Run `npm run docs:generate`. -->

# Errors & Failures Reference

> Джерело істини: `ExecutionFailureKind` + PHP classes that explicitly implement the classified failure contract.

## Failure kinds

| Kind | Retryable |
| --- | --- |
| `RETRYABLE` | yes |
| `PERMANENT` | no |
| `CONCURRENCY_CONFLICT` | yes |
| `POLICY_DENIED` | no |
| `BUDGET_EXCEEDED` | no |
| `EXTERNAL_UNAVAILABLE` | yes |

## Classified implementations

| Symbol | Source |
| --- | --- |
| `ExecutionFailureException` | `app/Kernel/Execution/ExecutionFailureException.php` |
| `LlmBudgetExceededException` | `app/Kernel/Llm/LlmBudgetExceededException.php` |
| `LlmProviderException` | `app/Kernel/Llm/LlmProviderException.php` |
| `StaleActionExecutionClaimException` | `app/Kernel/Action/StaleActionExecutionClaimException.php` |
