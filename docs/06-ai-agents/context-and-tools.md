---
title: Context and Tools
description: Межа між Agent reasoning, domain context і керованими tools/actions.
status: active
updated: 2026-09-11
kind: agent
---

# Context and Tools

У COS важливо розділяти **context for reasoning** і **capability for action**.

## Context

Context — це read-only набір фактів, достатній для рішення.

Domain визначає його через context builder. Kernel лише маршрутизує та застосовує generic safety mechanisms.

Добрий context:

- tenant-scoped;
- мінімальний;
- структурований;
- має зрозуміле походження;
- не містить зайвих secrets/PII;
- стабільний enough для evaluation/replay.

## Context is not memory dump

Не слід передавати Agent-у:

- всі CRM записи;
- повну історію компанії;
- unrestricted SQL result;
- credentials;
- internal tokens;
- raw documents, якщо потрібні лише три факти.

Більше context не означає краще reasoning. Часто це просто дорожчий спосіб зменшити signal-to-noise.

## Redaction

Перед LLM call sensitive data проходять через `SensitiveContextRedactor`.

Redaction policy має бути централізованою настільки, наскільки це generic concern, але Domain може визначати, які business fields узагалі допустимі в його agent context.

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

Фактично Action + handler + port є керованим tool boundary для mutation.

## Read tools

Для richer agents можуть з'являтися explicit read capabilities. Вони мають:

- бути registered/owned;
- tenant-scope-итись;
- мати deterministic input/output schema;
- не створювати hidden side effects;
- логувати usage, якщо це operationally important;
- повертати мінімально потрібні дані.

## Write tools

Write capability не повинна bypass Action lifecycle.

Навіть якщо зовнішній LLM framework називає це `tool_call`, всередині COS mutation має перетворитися на контрольовану Action.

```text
LLM tool call request
→ validate
→ ActionProposal
→ Policy
→ execution
```

## Domain ports as capability boundary

Domain-owned outbound contracts визначають, що Domain взагалі вміє просити у зовнішнього світу.

Приклади Sales:

- `DealRepositoryInterface`;
- `MessageGatewayInterface`;
- `FollowupRepositoryInterface`;
- `CrmGatewayInterface`.

Infrastructure реалізує ці contracts конкретними MySQL/CRM/messaging adapters.

Agent не повинен знати provider names.

## Context versioning

Для critical Agent decisions корисно мати можливість відтворити:

- context schema version;
- agent definition version;
- prompt/instruction version;
- model/provider metadata;
- structured output schema version.

Це дозволяє відрізнити «модель прийняла інше рішення» від «ми тихо змінили context builder три дні тому».

## Future MCP / external tool ecosystem

MCP або інший tool protocol має входити в COS як Infrastructure/Integration adapter, а не як обхід Kernel.

Правильний напрям:

```text
COS capability / port
 → MCP adapter
 → external MCP tool
```

або для agent-proposed mutation:

```text
Agent proposal
 → COS Action/Policy
 → MCP-backed handler
```

Таким чином заміна MCP server/provider не змінює бізнес-rule або policy.

## Invariant

> Context дає Agent факти. Action дає системі намір. Policy дає право. Adapter дає технічну можливість виконати дію.
