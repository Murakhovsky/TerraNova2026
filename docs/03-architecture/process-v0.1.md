---
title: Process V0.1 — канонічний фундамент процесів
description: Платформний Process Registry, структурна модель Kernel і межа міждоменних контрактів schema v4/v5.
status: active
updated: 2026-09-16
kind: architecture
contract: architecture-v1
---

# Process V0.1 — канонічний фундамент процесів

Process V0.1 переносить модель бізнес-процесів із внутрішніх інструментів документації на рівень платформи COS.

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

## Джерело правди

Machine-readable визначення процесів зберігаються в:

```text
resources/processes/*.json
```

`docs/.vitepress` більше не володіє окремою копією Process Registry.

Documentation, Mermaid projections, Knowledge Health, Capability Debt, Domain Process Coverage і Cross-Domain Process Topology споживають один платформний registry.

## Модель Kernel

`Kernel\Process` визначає універсальну структурну модель процесу:

- `ProcessDefinition`;
- `ProcessStep`;
- `ProcessEdge`;
- `RuntimeMapping`;
- `ProcessRegistryInterface`.

Kernel перевіряє структурні invariants (інваріанти), але не знає бізнес-семантики конкретного Domain і не перевіряє існування Domain capabilities через `ModuleCatalog`.

## Schema v4 і v5

Schema `v4` залишається валідною для same-domain процесів.

Schema `v5` дозволяє step іншого Domain, але лише якщо він містить структурний runtime mapping типу `contract`.

Evidence layer додає сильнішу перевірку: contract має бути підтверджений module evidence поточного checkout як `requires` від Process Domain до target Domain.

Для `sales.request-to-property-match`:

```text
Sales process
   ↓ requires
PropertyReferencePort
   ↓
Property / property.reference
```

Process залишається Sales-owned. Property зберігає ownership над Property capability та власним state.

## Runtime Registry

`Infrastructure\Process\JsonProcessRegistry`:

1. читає канонічні definitions із `resources/processes`;
2. гідрує Kernel model;
3. надається через DI service `cosProcessRegistry`.

Це робить одну Process model доступною для runtime, diagnostics, visualization та documentation.

## Чого V0.1 не робить

Process V0.1 **не є execution engine**.

Його задача на цьому етапі — зробити topology бізнес-процесів платформним контрактом, який може однаково споживатися різними частинами COS.

До V0.1 не входять:

- BPMN runtime;
- orchestration engine, який виконує process steps;
- автоматичне перетворення Process Registry у Queue jobs;
- перенесення ownership Domain state в Process layer.

## Відповідальність шарів

```text
Process Registry
    → структура бізнес-процесу

Domain
    → бізнес-семантика і state ownership

Module capabilities
    → канонічний словник можливостей Domain

Cross-domain contracts
    → дозволені межі між Domains

Evidence layer
    → перевірка claims проти current checkout

Documentation / Visualization
    → проєкції тієї самої моделі
```

## Інваріанти V0.1

1. На платформі існує один canonical Process Registry.
2. Documentation layer не володіє дублем process truth.
3. Schema `v4` і `v5` підтримуються одночасно.
4. Cross-domain step у `v5` вимагає contract mapping.
5. Verified `requires` semantics є відповідальністю evidence/module layer, а не Kernel.
6. Capability gaps залишаються явними й не маскуються вигаданими capabilities.
7. Mermaid, generated reference та topology є проєкціями, а не другим source of truth.
8. Process не отримує ownership над Domain state.
9. BPMN і process execution engine не входять у V0.1.

## Наслідок для COS

Після Process V0.1 бізнес-процес перестає бути лише сторінкою документації.

Він стає структурованою платформною сутністю, яку можна:

- перевіряти;
- візуалізувати;
- зв’язувати з capabilities;
- зв’язувати з runtime evidence;
- аналізувати на міждоменні переходи;
- використовувати як основу для майбутньої process intelligence та automation.

> Process описує, як рухається робота. Domain визначає, що означають бізнесові кроки. Kernel не привласнює собі семантику жодного з них.
