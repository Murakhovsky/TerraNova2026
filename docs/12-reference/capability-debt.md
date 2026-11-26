---
title: Беклог боргу можливостей
description: Згенерований backlog невирішених прогалин vocabulary capabilities, пов’язаних із канонічними кроками бізнес-процесів COS.
status: generated
updated: 2026-09-16
kind: reference
contract: reference-v1
generated: true
---

# Беклог боргу можливостей

Згенеровано з `docs/.vitepress/capability-debt.json`, кроків Process Registry із capability gaps і module capability authority поточного checkout. Не редагуйте цю сторінку вручну.

Capability debt означає, що бізнес-крок реальний, але Domain-власник ще не експонує достатньо семантичну discoverable capability. Це **не** означає відсутність runtime implementation.

## Підсумок

- **Відкритих debt items:** 0
- **High severity:** 0
- **Medium severity:** 0
- **Зачеплених Domains:** 0

| Domain | Відкрито | High | Medium | Low |
| --- | ---: | ---: | ---: | ---: |

## Пріоритетний backlog

| Severity | Domain | Процес / крок | Runtime evidence | Target capability | Resolution |
| --- | --- | --- | --- | --- | --- |

## Контракт закриття боргу

Debt item вважається закритим лише коли виконані всі умови:

1. Domain-власник декларує target capability у канонічному module `contributions.capabilities`.
2. Відповідний крок Process Registry замінює `capability: null` + `capability_gap` на цю задекларовану capability.
3. Відповідний item видалено з `capability-debt.json`.
4. Генерація документації та перевірки проходять з того самого checkout.

`check-capability-debt.mjs` забезпечує зв’язок 1:1 між Process Registry gaps і debt items. Він також відхиляє stale debt, якщо target capability вже існує, неправильний Domain ownership, невалідну severity або target name поза namespace Domain-власника.

## Політика severity

- `high` — capability gap прив’язаний до критичного кроку процесу.
- `medium` — gap прив’язаний до некритичного, але канонічного кроку процесу.
- `low` — зарезервовано для майбутніх неканонічних/optional класів debt; поточний workflow debt його не використовує.

Severity описує architecture-model debt, а не severity operational incident.

## Авторитетність і обмеження

- Process Registry володіє фактом існування capability gap.
- Capability Debt Registry володіє remediation metadata для цієї прогалини.
- Domain module manifests залишаються authority для capabilities, які реально існують.
- Runtime Evidence Resolver залишається authority для source/runtime verification.
- Generated Markdown є лише projection і ніколи не використовується як executable authority.
- Закриття debt може вимагати Domain patch release; documentation layer не мутує Domain manifests мовчки.
