---
title: ADR-0008 — main є канонічною гілкою
status: accepted
updated: 2026-09-16
kind: decision
---

# Контекст

До 2026-09-14 executable code і documentation publication мали різні branch roles: `COS` використовувався як code authority, а `main` як documentation authority. Після злиття `COS` у `main` така модель створювала штучний drift: CI мав checkout-ити дві гілки, generated reference синхронізувався між ними, а deployment усе ще залежав від старої branch role.

# Рішення

**`main` є єдиною canonical development branch для COS.**

```text
main
├─ executable code
├─ tests
├─ module manifests
├─ migrations
├─ Process Registry
├─ documentation generators
├─ narrative documentation
├─ generated reference
└─ CI / deployment metadata
```

AS-IS documentation перевіряється проти того самого commit SHA, з якого вона збирається. Generated reference будується з current checkout `main`, без checkout або sync із `COS`.

# Обґрунтування

- один commit описує одну executable reality;
- немає cross-branch generated-reference drift;
- code і docs можуть змінюватися атомарно;
- Process Registry, manifests і narrative docs бачать один tree;
- CI простіший і дешевший;
- deploy semantics відповідають development semantics;
- стару `COS` можна зберігати як historical branch, але вона більше не є authority.

# Розглянуті альтернативи

## `COS` як code branch, `main` як docs branch

Відхилено після merge: це вимагало б штучно підтримувати дві канонічні лінії після фактичного об’єднання коду.

## Генерувати reference в `COS` і копіювати в `main`

Відхилено: generated facts повинні походити з того самого tree, який проходить tests і deployment.

# Наслідки

Позитивні:

- `main` є єдиною точкою інтеграції;
- docs generators читають current code напряму;
- pushes/PRs перевіряють code/docs разом;
- branch badges, links і operational docs не потребують двогілкової моделі;
- документаційний drift стає звичайною помилкою одного commit, а не міжгілковою археологією.

Operational constraint:

- GitHub Environment `AWS TN2026` має дозволяти deployment із `main`; environment protection є repository-external configuration і не визначається самим code tree.

# Сумісність і міграція

- старі cross-branch sync scripts не є canonical pipeline;
- `docs:generate` запускає generators у current checkout;
- Docs CI не checkout-ить `COS` як source authority;
- runtime/docs deployment triggers орієнтуються на `main`;
- narrative docs і System Map показують `main` як code/docs authority.

# Перевірка

- `npm run docs:generate` працює з current checkout;
- generated-reference drift gate проходить;
- `npm run docs:generate:check` проходить;
- `npm run docs:check` звіряє Kernel/module/process facts із current checkout;
- `npm run docs:build` генерує `/public/docs` із current checkout;
- runtime CI/deploy запускається з `main` відповідно до workflow configuration.

# Пов’язані матеріали

- `docs/10-operations/documentation-build.md`
- `docs/09-development/documentation-rules.md`
- `.github/workflows/docs.yml`
- `.github/workflows/diagnostic.yml`
- `package.json`
