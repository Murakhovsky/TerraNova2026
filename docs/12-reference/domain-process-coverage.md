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

- **Installable Domains:** 10
- **Покрито канонічним процесом:** 6
- **Явних exemptions:** 4
- **Без покриття:** 0

| Domain | Версія | Статус | Процесів | Кроків | Capability mapped | Capability gaps | Debt |
| --- | --- | --- | ---: | ---: | ---: | ---: | ---: |
| `construction` · Construction | `0.1.0` | `exempt` | 0 | 0 | 0/0 | 0 | 0 |
| `diagnostic` · Diagnostics | `0.6.1` | `covered` | 1 | 7 | 0/7 | 7 | 7 |
| `finance` · Finance | `0.1.0` | `exempt` | 0 | 0 | 0/0 | 0 | 0 |
| `growth` · Growth | `0.34.0` | `covered` | 1 | 6 | 6/6 | 0 | 0 |
| `hr` · HR | `0.1.0` | `exempt` | 0 | 0 | 0/0 | 0 | 0 |
| `procurement` · Procurement | `0.1.0` | `exempt` | 0 | 0 | 0/0 | 0 | 0 |
| `property` · Property | `0.12.0` | `covered` | 1 | 6 | 6/6 | 0 | 0 |
| `real_estate` · Real Estate | `0.2.0` | `covered` | 1 | 7 | 7/7 | 0 | 0 |
| `sales` · Sales | `0.8.6` | `covered` | 2 | 13 | 1/13 | 12 | 12 |
| `service` · Service | `0.2.0` | `covered` | 1 | 7 | 7/7 | 0 | 0 |

## Канонічне ownership процесів

### Construction (`construction`)

- **Exempt:** V1 skeleton only; executable Construction process models are intentionally deferred until Construction runtime implementation.
- **Owner:** COS Architecture

### Diagnostics (`diagnostic`)

- `diagnostic.session-to-recommendation`

### Finance (`finance`)

- **Exempt:** V1 skeleton only; executable Finance process models are intentionally deferred until Finance runtime implementation.
- **Owner:** COS Architecture

### Growth (`growth`)

- `growth.opportunity-candidate-to-handoff`

### HR (`hr`)

- **Exempt:** V1 skeleton only; executable HR process models are intentionally deferred until HR runtime implementation.
- **Owner:** COS Architecture

### Procurement (`procurement`)

- **Exempt:** V1 skeleton only; executable Procurement process models are intentionally deferred until Procurement runtime implementation.
- **Owner:** COS Architecture

### Property (`property`)

- `property.submission-to-publication`

### Real Estate (`real_estate`)

- `real_estate.opportunity-to-reservation`

### Sales (`sales`)

- `sales.lead-to-managed-case`
- `sales.request-to-property-match`

### Service (`service`)

- `service.request-to-close`

## Контракт покриття

1. Для цього gate installable Domains є лише directories з канонічним `module.php`.
2. Кожний installable Domain має володіти щонайменше однією Process Registry definition або мати один explicit exemption.
3. Кожний `domain` у Process Registry має резолвитися до installable module manifest.
4. Exemption стає невалідним, щойно Domain отримує канонічний process.
5. Supporting Domain directories без `module.php` не підвищуються документацією до installable Domains мовчки.

## Exemptions

| Domain | Owner | Причина |
| --- | --- | --- |
| `construction` | COS Architecture | V1 skeleton only; executable Construction process models are intentionally deferred until Construction runtime implementation. |
| `finance` | COS Architecture | V1 skeleton only; executable Finance process models are intentionally deferred until Finance runtime implementation. |
| `hr` | COS Architecture | V1 skeleton only; executable HR process models are intentionally deferred until HR runtime implementation. |
| `procurement` | COS Architecture | V1 skeleton only; executable Procurement process models are intentionally deferred until Procurement runtime implementation. |

## Авторитетність і обмеження

- Module manifests визначають, які Domains є installable.
- Process Registry визначає канонічне ownership процесів.
- `process-coverage-exemptions.json` зберігає лише явні architecture exceptions.
- Domain directories без `module.php` залишаються supporting/non-installable areas і не є помилками coverage.
- Coverage означає наявність канонічної process model; воно не стверджує, що модель повна, автоматизована або runtime-verified.
