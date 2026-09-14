---
title: ADR-0008 — main is the canonical branch
status: accepted
updated: 2026-09-14
kind: decision
---

# Context

До 2026-09-14 executable code і documentation publication мали різні branch roles: `COS` використовувався як code authority, а `main` як documentation authority. Після злиття `COS` у `main` ця модель створювала штучний drift: CI мав checkout-ити дві гілки, generated reference синхронізувався між ними, а deploy runtime усе ще залежав від старої branch role.

# Decision

`main` є єдиною canonical development branch для COS.

```text
main
├─ executable code
├─ tests
├─ module manifests
├─ migrations
├─ documentation generators
├─ narrative documentation
├─ generated reference input/output
└─ CI / deployment metadata
```

AS-IS documentation перевіряється проти того самого commit SHA, з якого вона збирається. Generated reference будується локально з `main`, без checkout або sync із `COS`.

# Rationale

- один commit описує одну executable reality;
- немає cross-branch generated-reference drift;
- code і docs можуть змінюватися атомарно;
- CI простіший і дешевший;
- deploy semantics відповідають development semantics;
- стару `COS` можна зберігати як historical branch, але вона більше не є authority.

# Alternatives considered

## Залишити COS як code branch, main як docs branch

Відхилено після merge: це змушувало б штучно підтримувати дві канонічні лінії після того, як код уже об'єднано.

## Генерувати reference в COS і копіювати в main

Відхилено: generated facts повинні походити з того самого tree, який проходить tests і deployment.

# Consequences

Позитивні:

- `main` стає єдиною точкою інтеграції;
- docs generators читають current code напряму;
- pull requests і pushes у `main` перевіряють code/docs разом;
- branch badges, links і operational docs більше не потребують спеціальної двогілкової моделі.

Operational constraint:

- GitHub Environment `AWS TN2026` має дозволяти deployment із `main`; branch protection environment не є частиною repository code і налаштовується в GitHub settings.

# Compatibility / Migration

- старий `sync-generated-reference.mjs` видаляється;
- `docs:generate` знову запускає PHP generators локально;
- Docs CI більше не checkout-ить `COS`;
- runtime deployment trigger переходить на `main`;
- narrative docs і System Map показують `main` як code/docs authority.

# Verification

- `npm run docs:generate` працює з current checkout;
- `npm run docs:generate:check` проходить після generation;
- `npm run docs:check` звіряє Kernel/module facts із current checkout;
- `npm run docs:build` генерує `/public/docs` з current checkout;
- runtime CI/deploy запускається з `main`.

# Related

- `docs/10-operations/documentation-build.md`
- `docs/09-development/documentation-rules.md`
- `.github/workflows/docs.yml`
- `.github/workflows/diagnostic.yml`
- `package.json`
