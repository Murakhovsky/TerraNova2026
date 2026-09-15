---
title: {{title}}
description: {{title}} business workflow.
status: draft
updated: {{date}}
kind: workflow
contract: workflow-v2
process_state: as-is
process_id: TODO_PROCESS_ID
---

# {{title}}

## Business goal

Опишіть measurable business outcome.

## Actors

- actor;

## Trigger / input

Опишіть початкову подію або запит.

## Workflow

<ProcessDiagram process-id="TODO_PROCESS_ID" />

Основний business flow генерується з matching schema-v2 definition у `docs/.vitepress/processes/*.json`. Не дублюйте вручну ті самі `steps` та `edges` у Mermaid.

## Ownership view

<ProcessDiagram process-id="TODO_PROCESS_ID" view="ownership" direction="LR" />

Кожний registry step має оголосити primary responsible `owner`, який входить до `actors` process definition.

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
