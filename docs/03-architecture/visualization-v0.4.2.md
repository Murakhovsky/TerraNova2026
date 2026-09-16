---
title: Visualization V0.4.2 — аудит міждоменних залежностей
description: Machine-readable topology cross-domain contracts і CI enforcement для меж bounded contexts COS.
status: implemented
kind: architecture
updated: 2026-09-16
---

# Visualization V0.4.2 — аудит міждоменних залежностей

V0.4.2 закрив прогалину, яку показав Architecture Explorer: module `dependencies` описують module/runtime requirements, але не всі легітимні business interactions між bounded contexts.

Consumer-owned port не можна спрощувати до generic edge `Domain A depends_on Domain B`. Такий edge губить ownership semantics і може створити уявний circular module dependency там, де код коректно використовує dependency inversion.

## Канонічна модель

Cross-domain synchronous/integration boundaries декларуються в:

```text
ModuleContributions.cross_domain_contracts
```

Declaration містить:

```text
contract
role: requires | provides
counterpart
kind
purpose
```

Declaring module + role визначають semantic participants:

```text
Consumer Domain ── requires_contract ──▶ Contract
Provider Domain ── provides_contract ──▶ Contract
```

Namespace contract class окремо фіксує vocabulary ownership. Runtime Visualization не сканує PHP source, щоб вигадати цю topology. Source scanning використовується лише як CI verification layer.

## Поточна AS-IS topology

### Sales → Property

```text
Domains\Property\Contract\PropertyReferencePort
```

Sales `0.8.6` декларує `requires`; Property залишається authority для canonical Property state.

### Property → Sales

```text
Domains\Property\Application\Contract\PresentationSalesInterface
```

Property `0.12.0` потребує Sales-owned client-case/share context для presentation workflows. Port consumer-owned з боку Property, але Sales зберігає ownership над своєю бізнес-семантикою.

### Spatial → Property

```text
Domains\Spatial\Application\Contract\PropertyTourPublisherInterface
```

Spatial володіє publication boundary, а Property декларує `provides` canonical tour data через integration adapter.

## Contracts projection

Architecture Explorer має dedicated `Contracts` projection, яка містить:

- Domain nodes;
- Contract nodes;
- `owns` vocabulary ownership;
- `requires_contract` consumer edges;
- `provides_contract` provider edges.

`Dependencies` також може включати contract nodes, але ordinary `depends_on` залишається для module/runtime dependency evidence.

## CI audit

`tests/architecture/cross_domain_dependency_audit.php` сканує `app/Domains/*` і класифікує кожний cross-domain class reference.

Дозволено:

1. exact class, який оголошено canonical cross-domain contract;
2. exact legacy debt entry з audit allowlist.

Інші direct cross-domain references падають у CI.

Allowlist навмисно exact за file + class. Директорія з назвою `Legacy` не є дипломатичним імунітетом.

## Поточний legacy debt

Audit досі містить сім explicit direct Infrastructure references у чотирьох старих Telegram/Phalcon files:

- Sales `Shows.php` → Property `Objects`;
- Sales `Shows.php` → Identity `AppUsers`;
- Sales `Requests.php` → Identity `ListItems` і `Lists`;
- Property `Objects.php` → Identity `ListItems`;
- Property `ReObjects.php` → Identity `AppUsers` і `Lists`.

Ці dependencies дозволені лише як named migration debt. Якщо allowlist entry перестає існувати в коді, audit вважає його stale і вимагає видалення. Нові references такого типу не дозволяються.

## Зв’язок із Process Registry

Після Process V0.1 cross-domain process step використовує ту саму contract topology, а не малює нову інтеграційну семантику в документації.

```text
resources/processes/*.json
        ↓
step.domain != process.domain
        ↓
contract runtime mapping
        ↓
module cross_domain_contracts
        ↓
target Domain capability
        ↓
ProcessDiagram / reference
```

Schema `v5` вимагає structural `contract` mapping для foreign-Domain step, а evidence layer перевіряє, що process Domain реально декларує цей contract як `requires` до target Domain.

## Чому це важливо

Architecture Graph відповідає на питання:

> Які cross-domain boundaries існують у COS?

Process Registry відповідає:

> Які реальні business processes використовують ці boundaries?

Обидва шари спираються на один executable contract vocabulary. Mermaid/Cytoscape лише показують ці факти і не створюють власну версію істини.
