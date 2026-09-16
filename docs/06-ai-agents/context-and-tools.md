---
title: Контекст та інструменти
description: Межа між міркуванням Agent, доменним контекстом і керованими інструментами та діями.
status: active
updated: 2026-09-16
kind: agent
---

# Контекст та інструменти

У COS важливо розділяти **context for reasoning (контекст для міркування)** і **capability for action (можливість виконати дію)**.

## Контекст

Context є read-only набором фактів, достатнім для конкретного рішення.

Domain визначає його через context builder. Kernel лише маршрутизує запит і застосовує загальні механізми безпеки.

Добрий context:

- обмежений tenant;
- мінімальний;
- структурований;
- має зрозуміле походження;
- не містить зайвих secrets або PII;
- достатньо стабільний для evaluation і replay.

## Context не є дампом пам’яті

Не слід передавати Agent:

- всі записи CRM;
- повну історію компанії;
- unrestricted SQL result;
- credentials;
- internal tokens;
- сирі документи, якщо для рішення потрібні лише кілька фактів.

Більше context не означає краще reasoning. Часто це лише дорожчий спосіб зменшити співвідношення сигналу до шуму.

## Приховування чутливих даних

Перед викликом LLM чутливі дані проходять `SensitiveContextRedactor`.

Redaction policy має бути централізованою настільки, наскільки це загальна відповідальність. Domain при цьому визначає, які бізнесові поля взагалі дозволено включати до його Agent context.

## Tools у поточній архітектурі

COS не моделює tool як «довільну функцію, яку LLM може викликати». Безпечніша модель:

```text
Agent reasoning
 → structured ActionProposal
 → Policy
 → Approval if needed
 → Action Handler
 → outbound port
 → Infrastructure adapter
```

Фактично Action + handler + port є керованою межею інструмента для мутацій.

## Інструменти читання

Для складніших Agents можуть існувати явні read capabilities. Вони повинні:

- бути зареєстрованими й мати власника;
- обмежуватися tenant;
- мати детерміновану input/output schema;
- не створювати прихованих side effects;
- журналювати використання, якщо це операційно важливо;
- повертати лише потрібні дані.

## Інструменти запису

Write capability не повинна обходити життєвий цикл Action.

Навіть якщо зовнішній LLM framework називає це `tool_call`, всередині COS мутація має перетворитися на контрольовану Action.

```text
LLM tool call request
→ validate
→ ActionProposal
→ Policy
→ execution
```

## Domain ports як межа можливостей

Вихідні контракти, якими володіє Domain, визначають, що Domain взагалі може просити у зовнішнього світу.

Приклади Sales:

- `DealRepositoryInterface`;
- `MessageGatewayInterface`;
- `FollowupRepositoryInterface`;
- `CrmGatewayInterface`.

Infrastructure реалізує ці contracts конкретними MySQL, CRM та messaging adapters.

Agent не повинен знати назви провайдерів.

## Версіонування контексту

Для критичних рішень Agent корисно мати можливість відтворити:

- версію context schema;
- версію agent definition;
- версію prompt/instruction;
- model/provider metadata;
- версію structured output schema.

Це дозволяє відрізнити «модель прийняла інше рішення» від «ми непомітно змінили context builder».

## MCP та зовнішні інструменти

MCP або інший tool protocol має входити в COS як Infrastructure/Integration adapter, а не як обхід Kernel.

Правильний напрям:

```text
COS capability / port
 → MCP adapter
 → external MCP tool
```

Для мутації, яку пропонує Agent:

```text
Agent proposal
 → COS Action/Policy
 → MCP-backed handler
```

Тоді заміна MCP server або provider не змінює бізнес-Rule чи Policy.

## Інваріант

> Context дає Agent факти. Action дає системі намір. Policy дає право. Adapter дає технічну можливість виконати дію.
