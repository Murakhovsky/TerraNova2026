---
title: Довідник графа архітектури
description: Згенерований словник і каталог projections для канонічного COS Architecture Graph.
status: generated
kind: reference
generated: true
---

<!-- ЗГЕНЕРОВАНИЙ ФАЙЛ: НЕ РЕДАГУВАТИ ВРУЧНУ. Запустіть `npm run docs:generate`. -->

# Довідник графа архітектури

> Джерело істини: `ArchitectureGraphVocabulary` + `ArchitectureProjectionRegistry` у поточному checkout.

## Типи вузлів

| Константа | Значення |
| --- | --- |
| `TYPE_ACTION` | `action` |
| `TYPE_AGENT` | `agent` |
| `TYPE_CAPABILITY` | `capability` |
| `TYPE_CONTRACT` | `contract` |
| `TYPE_DOMAIN` | `domain` |
| `TYPE_EVENT` | `event` |
| `TYPE_EXTENSION_POINT` | `extension_point` |
| `TYPE_HANDLER` | `handler` |
| `TYPE_KERNEL` | `kernel` |
| `TYPE_POLICY` | `policy` |
| `TYPE_RULE` | `rule` |
| `TYPE_SERVICE` | `service` |

## Зв’язки

| Константа | Значення |
| --- | --- |
| `REL_CONTAINS` | `contains` |
| `REL_CONTRIBUTES` | `contributes` |
| `REL_CONTRIBUTES_TO` | `contributes_to` |
| `REL_DEPENDS_ON` | `depends_on` |
| `REL_GOVERNS` | `governs` |
| `REL_HANDLED_BY` | `handled_by` |
| `REL_OWNS` | `owns` |
| `REL_PRODUCES` | `produces` |
| `REL_PROPOSES` | `proposes` |
| `REL_PROVIDES_CONTRACT` | `provides_contract` |
| `REL_REQUIRES_CONTRACT` | `requires_contract` |
| `REL_TRIGGERS` | `triggers` |

## Канонічні projections

| Projection | Мітка |
| --- | --- |
| `system` | System |
| `runtime` | Runtime |
| `domain` | Domain |
| `dependencies` | Dependencies |
| `contracts` | Contracts |
| `events` | Events |
| `actions` | Actions |
| `agents` | Agents |
| `integrations` | Integrations |
| `code` | Code |

## Виконувана поверхня

Живий Architecture Explorer доступний у Web interface за `/cos/architecture`; ця сторінка документує vocabulary виконуваного графа, а не дублює його rendering.
