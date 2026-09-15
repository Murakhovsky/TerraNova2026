---
title: Agent Memory Model
description: Architecture rules for working context, retrieval, durable business facts and future agent memory in COS.
status: active
updated: 2026-09-15
kind: agent
---

# Agent Memory Model

У COS слово `memory` не повинно означати «складемо все, що колись бачила модель, у великий prompt і сподіваємося на характер». Потрібно розрізняти кілька різних речей.

> Ця сторінка визначає architecture contract. Вона не стверджує, що окремий універсальний Memory subsystem уже повністю реалізований AS-IS.

## Memory classes

### 1. Canonical business facts

```text
Sales / Property / Diagnostic / other Domain state
```

Це не Agent memory. Це authoritative Domain truth із власним lifecycle, audit і permissions.

### 2. Working context

Мінімальний read-only context, зібраний для конкретного reasoning/execution turn.

```text
Domain facts + relevant history + constraints + tool metadata
→ Context Builder
→ Agent
```

Working context може бути ephemeral і відтворюваним з canonical sources.

### 3. Conversation / interaction history

Історія повідомлень або попередніх рішень може бути корисною для continuity, але не стає canonical business truth автоматично.

### 4. Retrieval index

Search/vector/indexed representation є derived read model. Його можна rebuild-ити з authoritative sources; він не повинен тихо ставати єдиним місцем, де «зберігається правда».

### 5. Durable learned memory

Якщо COS у майбутньому зберігає learned preference, observation або inferred pattern, запис має explicit owner, provenance, confidence, retention policy та invalidation rules.

## Write rule

Agent не повинен самовільно вирішувати, що «варто запам'ятати назавжди».

```text
Agent observation
→ structured memory proposal
→ ownership / validation / policy
→ durable store (if allowed)
```

## Provenance

Для durable memory важливо знати:

- звідки взято факт;
- чи це observation, inference або user-provided statement;
- коли він створений/підтверджений;
- який tenant/domain scope;
- confidence/version;
- як його invalidate/update.

## Privacy and minimization

Memory не повинна бути обхідним шляхом навколо context minimization. Sensitive data, secrets і зайва PII не зберігаються «про запас» лише тому, що колись можуть знадобитися.

## Replayability

Для critical Agent decision бажано мати references на exact context snapshot/schema, agent version, instruction version і model metadata. Це дає можливість пояснити різницю між зміною model behavior і зміною memory/context source.

## Related

- [Context & Tools](./context-and-tools.md)
- [Agent Evaluation](./agent-evaluation.md)
- [LLM Governance](./llm-governance.md)
