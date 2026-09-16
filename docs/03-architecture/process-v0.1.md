---
title: Process V0.1 — канонічний фундамент процесів
description: Платформний Process Registry, структурна модель Kernel і межа cross-domain контрактів schema v4/v5.
status: active
updated: 2026-09-16
kind: architecture
contract: architecture-v1
---

# Process V0.1 — канонічний фундамент процесів

Process V0.1 переносить модель бізнес-процесів із внутрішнього tooling документації на рівень платформи COS.

Канонічний ланцюжок:

```text
Business
  ↓
Workflow
  ↓
Process Registry
  ↓
Domain
  ↓
Capability
  ↓
Runtime evidence / contract
  ↓
Service / Code
```

## Джерело істини

Machine-readable визначення процесів зберігаються у:

```text
resources/processes/*.json
```

`docs/.vitepress` більше не володіє окремою копією Process Registry. Документація, Mermaid-проєкції, Knowledge Health, Capability Debt, Domain Process Coverage і Cross-Domain Process Topology споживають один платформний registry.

## Kernel model

`Kernel\Process` визначає універсальну структуру процесу:

- `ProcessDefinition`;
- `ProcessStep`;
- `ProcessEdge`;
- `RuntimeMapping`;
- `ProcessRegistryInterface`.

Kernel перевіряє лише структурні інваріанти. Він не перевіряє існування Domain capabilities через `ModuleCatalog` і не знає бізнес-семантики конкретного домену.

## Schema v4 / v5

Schema v4 залишається валідною для same-domain процесів. Schema v5 дозволяє крок іншого Domain, але тільки якщо цей крок містить структурний runtime mapping типу `contract`.

Evidence layer додає сильнішу перевірку: contract має бути підтверджений current-checkout module evidence як `requires` від process Domain до target Domain.

Для `sales.request-to-property-match` це означає:

```text
Sales process
   ↓ requires
PropertyReferencePort
   ↓
Property / property.reference
```

Process залишається Sales-owned. Property зберігає ownership над Property capability та своїм state.

## Runtime registry

`Infrastructure\Process\JsonProcessRegistry` завантажує канонічні definitions із `resources/processes`, гідрує Kernel model і надається через DI service `cosProcessRegistry`.

Process V0.1 не додає execution engine. Поточна мета — зробити business-process topology платформним контрактом, який можна однаково споживати runtime, diagnostics, visualization та документацією.

## Інваріанти V0.1

1. Один canonical Process Registry на рівні платформи.
2. Documentation layer не володіє дублем process truth.
3. Schema v4 і v5 підтримуються одночасно.
4. Cross-domain step у v5 вимагає contract mapping.
5. Verified `requires` semantics лишаються відповідальністю evidence/module layer, а не Kernel.
6. Capability gaps залишаються явними, а не маскуються вигаданими capabilities.
7. Mermaid, generated reference та topology є проєкціями, а не другим source of truth.
8. BPMN та process execution engine не входять у V0.1.
