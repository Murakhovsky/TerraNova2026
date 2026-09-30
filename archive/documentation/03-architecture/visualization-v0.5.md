---
title: Visualization V0.5 — evidence виконання процесів
description: Evidence-backed verification layer між Process Registry, canonical runtime facts і Mermaid documentation без перетворення renderer-а на source of truth.
status: active
updated: 2026-09-16
kind: architecture
contract: architecture-v1
---

# Visualization V0.5 — evidence виконання процесів

Visualization V0.5 не створює «ще одну Mermaid-систему». Його задача — закрити семантичний розрив між process topology та поточним executable COS: **що саме доводить, що mapping кроку процесу існує в current checkout?**

## Ціль

Канонічний ланцюжок:

```text
Platform Process Registry
resources/processes/*.json
        ↓
Runtime mappings
        ↓
Runtime Evidence Catalogue
        ↓
Derived verification
        ↓
Business Process Reference / ProcessDiagram / Mermaid
```

Mermaid є presentation. Generated Markdown є presentation. Вони не можуть верифікувати одне одного.

## Дві незалежні осі

Business state та verification не змішуються:

```text
Business state                 Derived verification
-------------                  --------------------
as-is                          documented
to-be                          source-verified
                               runtime-verified
```

`as-is` / `to-be` є authored business meaning.

Verification обчислюється з current-checkout evidence і не записується автором у process definition.

## Каталог доказів runtime

Machine-readable evidence catalogue генерується через:

```text
docs/.vitepress/generate-runtime-evidence.php
```

Він читає current checkout authorities безпосередньо.

| Mapping | Canonical authority | Strength / role |
| --- | --- | --- |
| `use_case` | Domain `Application/UseCase/*.php` | source |
| `command` | Domain `Application/DTO/*Command.php` | source |
| `source` | exact repository path + optional symbol | source |
| `event` | explicit Domain event catalogue | runtime |
| `contract` | module `cross_domain_contracts` | runtime |
| `capability` | module `contributions.capabilities` | capability authority |

Shared resolver `process-runtime-evidence.mjs` використовується checks і generated Business Process reference.

## Алгоритм verification

Для critical process steps:

```text
хоча б один critical step без resolvable evidence
        ↓
DOCUMENTED

усі critical steps мають source evidence
        ↓
SOURCE-VERIFIED

усі critical steps мають runtime-strength evidence
        ↓
RUNTIME-VERIFIED
```

Найсильніший status застосовується лише коли **кожний critical step** відповідає рівню.

`RUNTIME-VERIFIED` означає structural verification проти canonical runtime/architecture registries. Це не observed end-to-end production trace. Факт реального execution належить runtime observability, а не документаційному inference.

## Поточний Process Registry contract

Після Process V0.1 канонічні definitions живуть у:

```text
resources/processes/*.json
```

Documentation layer більше не володіє власним Process Registry.

Підтримуються:

- schema `v4` для same-domain workflows;
- schema `v5` для contract-guarded cross-domain steps.

Process definition містить topology, owners, step Domain, capability або explicit gap, criticality та runtime mappings. Authored `verification` заборонена.

Це замінює старий V0.5-era schema `v3` як поточний contract. Історична роль V0.5 полягала у введенні evidence-backed verification; platform-level Process V0.1 пізніше переніс сам registry у загальну архітектуру COS.

## Міждоменні докази

Visualization V0.4.2 ввів first-class contracts:

```text
Consumer Domain
   │ requires_contract
   ▼
 Contract
   ▲ provides_contract
   │
Provider Domain
```

Process schema `v5` використовує той самий contract FQCN для foreign-Domain step.

Evidence layer перевіряє:

1. contract існує в current module manifests;
2. process Domain декларує його як `requires`;
3. `counterpart` відповідає target step Domain;
4. step використовує canonical target Domain capability.

Таким чином Architecture truth і Process truth сходяться на одному executable boundary.

## Покриття можливостей

Capability coverage є окремою від verification.

Крок може мати source/runtime evidence, але все ще показувати `capability_gap`, якщо Domain manifest не має достатньо точного semantic capability identifier.

```text
runtime implementation exists
        ≠
capability vocabulary is complete
```

Capability debt фіксується окремо й не маскується broad workspace permission.

## Межі автоматичного виведення

Visualization V0.5 не генерує `sequenceDiagram` лише з generic steps. Для sequence потрібні participants, messages, call direction та interaction semantics.

Так само `stateDiagram` не виводиться лише тому, що step має `kind: state`. Entity lifecycle потребує canonical state-machine semantics.

Convincing diagram із вигаданою семантикою гірший за відсутність diagram, бо помилка в ньому виглядає як документація.

## Перевірки

Documentation/Visualization checks перевіряють:

- Process Registry topology та reachability;
- authored business state;
- заборону authored verification;
- step ownership і Domain;
- capability або explicit gap;
- cross-domain contract evidence для schema `v5`;
- runtime mapping resolution проти current checkout;
- generated reference drift;
- required ProcessDiagram projections.

## Поточний результат

```text
Cytoscape
  = з чого складається COS і які contracts/capabilities існують

Process Registry + ProcessDiagram/Mermaid
  = як рухається робота через ці межі

Runtime Evidence
  = наскільки твердження процесу підтримані current executable COS
```

Visualization та Documentation таким чином є двома проєкціями однієї inspectable operating model, а не двома колекціями красивих схем, які випадково використовують однакові слова.
