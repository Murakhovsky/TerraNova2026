---
title: {{title}}
description: {{title}} business workflow.
status: draft
updated: {{date}}
kind: workflow
contract: workflow-v2
process_state: as-is
---

# {{title}}

## Business goal

Опишіть measurable business outcome.

## Actors

- actor;

## Trigger / input

Опишіть початкову подію або запит.

## Workflow

```mermaid
flowchart TD
    A[Trigger] --> B[Use Case / Command]
    B --> C[Domain validation]
    C --> D[State change / Event]
    D --> E[Result]
```

Diagram має показувати meaningful business sequence, а не копіювати назви всіх класів.

## Decision points

Опишіть meaningful decisions.

## Events

Дайте links на generated event reference замість ручного дублювання exact strings.

## Failure paths

Опишіть expected failures, retries/idempotency і denied paths.

## Invariants

Зафіксуйте правила, які не можна порушити.

## UI surfaces

Покажіть delivery surfaces без перенесення business ownership у UI.

## Code map

```text
app/Domains/...
```
