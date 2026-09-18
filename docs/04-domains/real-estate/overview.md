---
title: Огляд домену Real Estate
description: Межа V1 домену Real Estate для брокерського lifecycle поверх канонічного Property registry.
status: active
updated: 2026-09-18
kind: domain
contract: domain-v1
---

# Огляд домену Real Estate

Real Estate `0.1.0` є orchestration Domain для брокерських процесів. Він не створює другого реєстру нерухомості й не переписує існуючі catalog, objects, presentations або property data.

## Призначення

```text
Property reference → Mandate / BrokerageCase → Showing → Offer
```

Real Estate оперує ідентифікаторами канонічних Property assets. Початковий словник: `BrokerageCase`, `Mandate`, `Showing`, `Offer`.

## Поточний стан

```text
id: real_estate
version: 0.1.0
dependency: property
runtime: disabled
persistence: none
routes: none
process model: explicitly deferred
```

Існуючий UI і Property runtime залишаються без переписування; бізнес-логіка переноситься сюди лише вертикальними slice-ами, коли її ownership справді є брокерським.

## Межі

`Property` залишається source of truth для asset registry, identity, inventory, listing/publication, catalog і property presentation. `RealEstate` володіє лише брокерським lifecycle та не пише напряму в Property tables.
