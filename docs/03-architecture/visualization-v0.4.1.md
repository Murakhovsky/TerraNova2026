---
title: Visualization V0.4.1 — посилення Architecture Explorer
description: Automation topology, dependency evidence і projection-aware поведінка Architecture Explorer.
status: implemented
kind: architecture
updated: 2026-09-16
---

# Visualization V0.4.1 — посилення Architecture Explorer

Visualization V0.4.1 перетворив Explorer із renderer-а над module metadata на повноціннішу surface для перевірки архітектури. Graph і далі походить із канонічних контрактів COS; hardening свідомо не покладається на regex/static-source guessing як runtime source of truth.

```text
ModuleCatalog + DomainModuleRegistry
              ↓
      ArchitectureGraphProvider
              ↓
        Canonical Graph
        ↙            ↘
 dependency facts   automation topology
              ↓
   ArchitectureProjectionRegistry
              ↓
 server-side GraphView projection
              ↓
       Cytoscape Explorer
```

## Automation topology

Canonical graph почав явно відображати bootstrap automation definitions:

```text
Event ──triggers──> Rule ──produces──> Action ──handled_by──> Handler
                                      ↑
Agent ─────────────proposes────────────┤
Policy ────────────governs─────────────┘
```

Нові node types: `rule`, `policy`.

Нові relations: `triggers`, `produces`, `governs`.

Rules і Policies у цій projection є **bootstrap defaults**, а не effective tenant configuration.

Kernel тому розділяє:

```text
BootstrapRuleProvidingModuleInterface
BootstrapPolicyProvidingModuleInterface
```

та tenant-aware runtime contracts:

```text
RuleProvidingModuleInterface
PolicyProvidingModuleInterface
```

Visualization читає лише відповідні contracts і не підміняє tenant-specific state умовним `default` runtime state.

## Dependency evidence

`depends_on` перестав бути анонімним edge. Dependency metadata фіксує provenance факту.

Canonical evidence sources включають:

- `manifest.kernel_constraint`;
- `manifest.dependencies`;
- `runtime_module_contract`;
- `automation.agent`;
- `automation.rule.trigger`;
- `automation.rule.effect`;
- `automation.policy`.

Derived cross-domain dependencies створюються лише після того, як відоме canonical ownership Event/Action.

V0.4.1 не намагається виводити архітектуру з довільних PHP imports. Source scanning може використовуватися для CI verification, але не як runtime architecture truth.

## Projection-aware layouts

Projection definitions передають renderer-neutral layout intent через Kernel registry description contract.

| Projection | Layout intent |
| --- | --- |
| System | hierarchical |
| Runtime | flow |
| Domain | radial |
| Dependencies | hierarchical |
| Events | flow |
| Actions | flow |
| Agents | radial |
| Integrations | hierarchical |
| Code | force |

Browser layer мапить ці hints на Cytoscape algorithms. Renderer-specific algorithm names не входять у Kernel або Architecture graph semantics.

## Server-side Domain focus

Explorer має read endpoint:

```text
GET /cos/architecture/graph?view=domain&focus=domain:sales&depth=2
```

`focus` і `depth` застосовуються server-side через `GraphView`. Browser не мусить отримувати повний Domain graph лише для того, щоб локально відкинути більшу його частину.

`depth=all` використовує повну projection.

## Explorer hardening

Active projection володіє своїми visible statistics, filters і layout intent.

Node details показують не лише raw metadata, а й:

- projection/layout;
- version/source;
- Domain ownership breakdown;
- incoming/outgoing relations;
- edge provenance.

Raw metadata лишається secondary diagnostic view.

## Інваріанти

- `Kernel\Visualization` залишається renderer- та Architecture-agnostic.
- Bootstrap automation contracts належать Kernel Module contracts, бо їх можуть споживати provisioning, documentation і visualization.
- Tenant-effective Rules/Policies не вбудовуються в platform-level Architecture Graph.
- Architecture semantics живуть у `Infrastructure\Visualization\Architecture`.
- Web компілюється проти Kernel graph/projection contracts.
- Cytoscape залишається renderer-ом, а не джерелом архітектурної істини.
- V0.4.1 посилює підтверджені факти, але не вигадує undeclared dependencies.
