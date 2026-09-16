---
title: Покриття доменів бізнес-процесами
description: Згенероване покриття installable COS Domains канонічними моделями Process Registry.
status: generated
updated: 2026-09-16
kind: reference
contract: reference-v1
generated: true
---

# Покриття доменів бізнес-процесами

Згенеровано з installable `app/Domains/*/module.php`, definitions Process Registry і явних process-coverage exemptions. Не редагуйте цю сторінку вручну.

Цей довідник відповідає на навмисно незручне питання: чи кожний installable business Domain має щонайменше одну канонічну process model, або явно пояснив, чому її немає?

## Підсумок

- **Installable Domains:** 3
- **Покрито канонічним процесом:** 3
- **Явних exemptions:** 0
- **Без покриття:** 0

| Domain | Версія | Статус | Процесів | Кроків | Capability mapped | Capability gaps | Debt |
| --- | --- | --- | ---: | ---: | ---: | ---: | ---: |
| `diagnostic` · Diagnostics | `0.6.1` | `covered` | 1 | 7 | 0/7 | 7 | 7 |
| `property` · Property | `0.12.0` | `covered` | 1 | 6 | 6/6 | 0 | 0 |
| `sales` · Sales | `0.8.6` | `covered` | 2 | 13 | 1/13 | 12 | 12 |

## Канонічне ownership процесів

### Diagnostics (`diagnostic`)

- `diagnostic.session-to-recommendation`

### Property (`property`)

- `property.submission-to-publication`

### Sales (`sales`)

- `sales.lead-to-managed-case`
- `sales.request-to-property-match`

## Контракт покриття

1. Для цього gate installable Domains є лише directories з канонічним `module.php`.
2. Кожний installable Domain має володіти щонайменше однією Process Registry definition або мати один explicit exemption.
3. Кожний `domain` у Process Registry має резолвитися до installable module manifest.
4. Exemption стає невалідним, щойно Domain отримує канонічний process.
5. Supporting Domain directories без `module.php` не підвищуються документацією до installable Domains мовчки.

## Exemptions

Активних exemptions немає.

## Авторитетність і обмеження

- Module manifests визначають, які Domains є installable.
- Process Registry визначає канонічне ownership процесів.
- `process-coverage-exemptions.json` зберігає лише явні architecture exceptions.
- Domain directories без `module.php` залишаються supporting/non-installable areas і не є помилками coverage.
- Coverage означає наявність канонічної process model; воно не стверджує, що модель повна, автоматизована або runtime-verified.
