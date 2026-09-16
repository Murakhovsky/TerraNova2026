---
title: {{title}}
description: Бізнес-процес {{title}}.
status: draft
updated: {{date}}
kind: workflow
contract: workflow-v2
process_state: as-is
process_id: TODO_PROCESS_ID
---

# {{title}}

## Бізнес-мета

Опишіть вимірюваний бізнес-результат.

## Учасники

- учасник;

## Тригер / вхідні дані

Опишіть початкову подію або запит.

## Процес

<ProcessDiagram process-id="TODO_PROCESS_ID" />

Основний бізнес-потік генерується з відповідного Process Registry definition у `resources/processes/*.json`. Не дублюйте вручну ті самі `steps` та `edges` у Mermaid.

## Представлення відповідальності

<ProcessDiagram process-id="TODO_PROCESS_ID" view="ownership" direction="LR" />

Кожний registry step має оголосити primary responsible `owner`, який входить до `actors` process definition.

## Представлення можливостей

<ProcessDiagram process-id="TODO_PROCESS_ID" view="capability" direction="LR" />

Кожний step має canonical Domain capability або explicit capability gap.

## Представлення доменів

Для schema-v5 процесу з cross-domain steps додайте:

```html
<ProcessDiagram process-id="TODO_PROCESS_ID" view="domain" direction="LR" />
```

Cross-domain step допустимий лише через verified `requires` contract від Process Domain до Domain кроку.

## Точки рішень

Опишіть змістовні рішення.

## Події

Дайте посилання на generated event reference замість ручного дублювання точних рядків.

## Шляхи помилок

Опишіть очікувані failures, retries/idempotency і denied paths.

## Інваріанти

Зафіксуйте правила, які не можна порушити.

## Інтерфейсні поверхні

Покажіть delivery surfaces без перенесення business ownership у UI.

## Карта коду

```text
app/Domains/...
```
