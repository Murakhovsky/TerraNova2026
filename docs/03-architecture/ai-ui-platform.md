---
title: Платформа AI UI
description: Канонічний context-aware Agent UI для structured results, evidence, governed actions та headless UIContext.
status: active
updated: 2026-09-21
kind: architecture
---

# Платформа AI UI

Wave 12.14 додає Agent UI як частину Experience Platform, а не як окремий чат-застосунок.

Канонічний потік:

```text
Workspace / Entity
      ↓
headless UIContext
      ↓
Agent Runtime
      ↓
structured AgentResult
      ↓
Agent Run projection
      ↓
Agent UI
      ↓
UIAction / Policy / Approval / Action runtime
```

AI не отримує окремий mutation path.

## Модель запуску Agent

Web читає tenant-scoped `AgentRunProjection` через `AgentRunReadModelInterface`.

Projection містить:

- agent name/version;
- subject;
- status;
- structured output;
- context references;
- confidence;
- provider/model;
- token usage;
- cost;
- duration;
- error;
- correlation id;
- timestamps.

MySQL adapter є Infrastructure implementation. Twig і Web controllers не читають `cos_agent_runs` напряму.

## Структурований результат

`StructuredAgentResultFactory` перетворює output у typed presentation objects:

- summary;
- recommendations;
- warnings;
- metrics;
- entities;
- evidence.

AI output не є HTML.

Усі model-generated strings проходять стандартний Twig escaping. У Agent UI заборонено `|raw` для model output.

## Дії Agent

Agent-generated `cos_actions` читаються як `AgentActionProjection`.

Lifecycle показується окремо від права виконання.

Кнопки не будуються з `proposed_actions` model output. Вони резолвляться тільки через canonical:

```text
Agent Action
   ↓
EntityRef
   ↓
UIActionResolver
   ↓
AI_PROPOSAL placement
   ↓
Permission + Policy / Approval lifecycle
```

Тому Agent recommendation і executable UIAction є різними поняттями.

Browser лише диспатчить `cos:workspace-action`. Він не виконує command через fetch.

## Контекст UI без прив’язки до renderer

`UIContext` описує поточну UI-поверхню для AI без HTML:

- workspace;
- active entity;
- selected entities;
- available governed actions;
- active filters;
- capabilities.

`UIContextFactory` повторно перевіряє organization/role і валідність workspace/entity.

Available actions походять з `UIActionResolver` з placement `ai_proposal`.

Цей contract може використовувати Web, майбутній Native shell або Agent orchestration без залежності від Twig.

## Поверхня Shell

Shell має одну глобальну AI panel.

На Workspace сторінці Shell читає canonical DOM markers:

- `data-workspace-id`;
- `data-workspace-platform-entity-key-value`.

Вони передаються server-side у `/workspace/ai`.

На сторінці без Workspace AI panel показує recent tenant Agent runs без вигаданого entity context.

## Безпека

AI UI не є authorization boundary.

Заборонено:

- виконувати model-proposed mutation напряму;
- будувати action button лише з model JSON;
- використовувати model HTML;
- вставляти output через `raw`;
- читати Agent tables із Stimulus;
- робити browser `fetch()` для Agent execution;
- передавати organization id із browser як authority;
- обходити UIAction permission/confirmation;
- дублювати Policy або Approval у JavaScript.

## Спостережуваність

Agent run показує:

- correlation id;
- provider/model;
- confidence;
- duration;
- token usage;
- cost.

Це presentation projection для debugging та операційної прозорості. Audit/trace runtime лишається authoritative history.

## Мобільний режим

AI panel на вузькому viewport стає bottom sheet і зберігає:

- structured result;
- evidence;
- governed actions;
- confirmations;
- scrollable run history;
- safe area.

Окремої mobile AI logic немає.

## Перевірка

`cos:web:ai-ui:smoke`:

1. створює synthetic completed Agent run;
2. створює реальну Agent-sourced Kernel Action;
3. читає run через `AgentRunReadModelInterface`;
4. будує headless `UIContext`;
5. резолвить Agent action через `UIActionResolver(AI_PROPOSAL)`;
6. рендерить Agent UI;
7. перевіряє structured result/evidence;
8. перевіряє, що model-provided `<script>` escaped;
9. прибирає synthetic resources.

Architecture gate окремо перевіряє dependency direction і відсутність browser execution/HTML trust.
