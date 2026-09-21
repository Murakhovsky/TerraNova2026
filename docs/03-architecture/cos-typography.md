---
title: COS Typography Contract
description: PHASE 3 visual typography lab and canonical type semantics for COS Experience Platform.
status: active
updated: 2026-09-21
kind: architecture
---

# COS Typography Contract — PHASE 3

## Purpose

Typography is part of the COS operational language, not page decoration.

PHASE 3 fixes the semantic type contract before the canonical component visual pass:

- dense UI must stay readable at 12–16 px;
- Ukrainian and Latin copy use the same hierarchy;
- financial/data surfaces use aligned numerals;
- type scale is semantic and shared by every Domain;
- font experiments remain reversible and do not change frontend architecture.

## Candidate lab

`/dev/ui` compares the same content in three candidate stacks:

1. Geist;
2. Inter;
3. IBM Plex Sans.

The comparison always includes:

- `123,450 €`;
- `Company Operating System`;
- `Потенційний клієнт`;
- `Продаж житлового комплексу`;
- `Pipeline Forecast`;
- normal operational body copy.

The lab does not silently download third-party fonts. If a candidate family is not installed/bundled, the declared fallback stack is used. A brand font is adopted only with an explicit asset/licensing decision.

## Production baseline

Until the brand-face decision is frozen, the production baseline remains Inter with system fallbacks.

This avoids changing every production surface merely to run a visual experiment.

Candidate stacks are expressed only through semantic variables:

```text
--cos-font-candidate-geist
--cos-font-candidate-inter
--cos-font-candidate-plex
```

No Domain may hardcode its own font family.

## Semantic type roles

Canonical roles:

```text
Display
Title
Heading
Body
Meta
Label
Numeric / Money
```

They map to the existing COS type scale and shared line-height/weight tokens.

## Financial/data typography

Aligned values use:

```css
font-variant-numeric: tabular-nums lining-nums;
```

Canonical semantic hooks:

```text
.cos-numeric
.cos-money
.cos-metric__value
.cos-data-grid__numeric
```

This is required for money, KPI, delta, forecast, variance and other vertically compared data.

## Domain rule

Domains provide content and business semantics.

Domains do not define:

- font families;
- independent type scales;
- random heading sizes;
- local financial number styling.

## Completion criteria

PHASE 3 is complete when:

- the three candidates are visible side by side in `/dev/ui`;
- the exact reference strings are present;
- a canonical semantic type scale exists;
- tabular numeric behavior is executable CSS, not only documentation;
- production remains stable while candidates are evaluated;
- CI prevents deletion of the typography contract.

The final brand-font selection can happen after visual review without changing the Web foundation.
