---
title: Модель пам’яті Agent
description: Архітектурні правила для робочого контексту, пошуку, довготривалих бізнес-фактів і майбутньої пам’яті Agent у COS.
status: active
updated: 2026-09-16
kind: agent
---

# Модель пам’яті Agent

У COS слово `memory` не повинно означати «складемо все, що колись бачила модель, у великий prompt і сподіватимемося на характер». Треба розрізняти кілька різних типів інформації.

> Ця сторінка визначає архітектурний контракт. Вона не стверджує, що окремий універсальний Memory subsystem уже повністю реалізований AS-IS.

## Класи пам’яті

### 1. Канонічні бізнес-факти

```text
Sales / Property / Diagnostic / other Domain state
```

Це не Agent memory. Це authoritative Domain truth із власним lifecycle, audit і permissions.

### 2. Робочий контекст

Мінімальний read-only context, зібраний для конкретного reasoning або execution turn.

```text
Domain facts + relevant history + constraints + tool metadata
→ Context Builder
→ Agent
```

Working context може бути короткоживучим і відтворюваним із канонічних джерел.

### 3. Історія взаємодії

Історія повідомлень або попередніх рішень може бути корисною для continuity, але не стає канонічною бізнес-правдою автоматично.

### 4. Індекс пошуку

Search/vector/indexed representation є derived read model. Його можна перебудувати з authoritative sources; він не повинен непомітно ставати єдиним місцем, де «зберігається правда».

### 5. Довготривала learned memory

Якщо COS у майбутньому зберігає learned preference, observation або inferred pattern, запис має мати:

- явного власника;
- provenance;
- confidence;
- retention policy;
- правила invalidation/update.

## Правило запису

Agent не повинен самовільно вирішувати, що «варто запам’ятати назавжди».

```text
Agent observation
→ structured memory proposal
→ ownership / validation / policy
→ durable store (if allowed)
```

## Походження даних

Для durable memory важливо знати:

- звідки взято факт;
- чи це observation, inference або user-provided statement;
- коли його створено або підтверджено;
- який tenant/domain scope;
- confidence/version;
- як його invalidate або update.

## Приватність і мінімізація

Memory не повинна бути обхідним шляхом навколо context minimization. Sensitive data, secrets і зайва PII не зберігаються «про запас» лише тому, що колись можуть знадобитися.

## Відтворюваність

Для критичних рішень Agent бажано зберігати references на:

- точний context snapshot або schema;
- agent version;
- instruction version;
- model metadata;
- джерела retrieved information.

Це дозволяє відрізнити зміну behavior моделі від зміни memory/context source.

## Інваріант

> Канонічна бізнес-правда живе в Domain. Agent працює з контрольованим контекстом. Пам’ять, яка переживає один запуск, повинна мати власника, походження та правила життєвого циклу.

## Пов’язані сторінки

- [Контекст та інструменти](./context-and-tools.md)
- [Оцінювання Agent](./agent-evaluation.md)
- [Керування LLM](./llm-governance.md)
