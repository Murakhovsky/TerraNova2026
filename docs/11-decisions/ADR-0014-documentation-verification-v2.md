---
title: ADR-0014 — Каркас документації та перевірки V2
description: Відокремити поточну правду від історії міграцій та перевести QA з version gates на invariant/behavior suites.
status: accepted
updated: 2026-09-29
kind: decision
---

# ADR-0014 — Каркас документації та перевірки V2

## Контекст

До COS V1 репозиторій накопичив сотні version/wave tests, десятки GitHub workflows та паралельний корпус migration documentation. Це було корисно під час швидкого strangler/cutover розвитку, але перетворило історію реалізації на постійну runtime-структуру QA і знань.

## Рішення

1. `docs/` описує current truth; migration/release snapshots виходять з published documentation.
2. Version/Wave tests стають historical evidence і не входять до active runner.
3. Active tests організуються за Contract, Unit, Integration, Smoke та Browser/E2E semantics.
4. `bin/verify` є єдиною test orchestration точкою.
5. GitHub Actions скорочується до кількох responsibility-based workflows.
6. Application tests не залежать від wording Markdown або конкретного workflow YAML.

## Обґрунтування

Інваріант живе довше за реліз. Назва `tenant isolation` пояснює, що захищається; назва `v0380` пояснює лише, коли це колись з'явилося.

## Альтернативи

Залишити всі історичні gates активними відхилено через дублювання, semantic drift та дедалі вищу вартість зміни.

Повністю видалити всю історію одразу відхилено на першому етапі: стабілізація V1 зберігає її у `tests/history` та `archive/documentation`.

## Наслідки

Активна test suite стає меншою й зрозумілішою. Історичні tests більше не створюють false confidence. Корисний regression з history потрібно явно підняти до поточного invariant test.

## Перевірка

`bash bin/verify fast`, docs CI та canonical runtime workflow мають бути green; active workflows не мають version/wave naming.
