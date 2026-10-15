---
title: Контрольоване відновлення Engineering після відхиленої архітектури
description: Процедура одноразового Architect, незалежного Security Review та свідомого дозволу Development.
status: active
updated: 2026-10-15
kind: guide
---

# Контрольоване відновлення Engineering

## Причина й мета

У процесі `c2e88072-6791-4385-986d-e6c099093774` з'явилося
31 AgentRun, після чого Principal Architect відхилив архітектуру головної
сторінки COS. Повторна команда «Продовжити» не виконує `BLOCKED →
ARCHITECTURE_PENDING` і не розв'язує відкриті findings `SEC-001…SEC-003`.

**Мета пакета:** окрема операторська процедура, яка не робить фіктивного
Human Gate, не скидає історію запусків, не обходить незалежний Security
Specialist і не запускає Developer на неперевіреній архітектурі.

## Етапи оператора

1. Розгорнути оновлений `main`. Перевірити, що обидва воркери Engineering
   та `engineering_immediate` працюють і доступні з авторизованого інтерфейсу.
2. Відкрити заблоковану задачу як Manager і перевірити `SEC-001…SEC-003`,
   версію `ARCHITECTURE_DECISION`, прив'язку до репозиторію та кількість
   активних AgentRun.
3. Натиснути **«Підготувати Security recovery»** або
   `POST /api/engineering/features/{id}/security-recovery/prepare`.
   Backend перевіряє tenant, менеджера, CSRF, `BLOCKED`, останній
   `REJECTED`, історичний `SECURITY_REVIEW_REPORT`, відсутність працюючих
   агентів і вже виконаного recovery для того самого Security-звіту.
   Далі фіксує людський перехід у `ARCHITECTURE_PENDING`, але
   ставить runtime на технічну паузу `STALLED` та блокує звичайну чергу.
4. Натиснути **«Один Architect + Security Review»**. Через незалежний
   `engineering_immediate` worker запускається рівно одна логічна спроба
   Architect (початкові докази та максимум **один** додатковий раунд читання
   файлів), далі, лише після схвалення архітектури, один незалежний
   Security Specialist. Немає автоматичного Developer і немає циклу
   `Security → Architect → Security → Architect`.
5. При `ARCHITECT_NOT_APPROVED`, `SECURITY_REWORK_REQUIRED` або `FAILED`
   зупинка зберігається. Потрібні нові конкретні докази та нове незалежне
   рішення, а не повтор того самого recovery ID.
6. При `SECURITY_APPROVED_AWAITING_RELEASE` Manager вдруге підтверджує
   **«Дозволити Development після Security»**. Backend повторно
   перевіряє `APPROVED` від незалежного Security Specialist, збіг хешу
   перевіреної та чинної архітектури, стан `DEVELOPMENT_RUNNING` і
   відсутність RUNNING агентів.
7. Після дозволу активується звичайний pipeline, але лише
   **вісім додаткових AgentRun** понад зафіксований на момент дозволу
   лічильник. Без подальших явних рішень цей бюджет не розширюється.

## Події та захист

- Дозвіл і кожна контрольна точка зберігаються в незмінній історії
  `feature.previous_context`, з ідентифікаторами review/архітектури,
  автора, workflow та revision.
- Події `engineering.security_recovery_checkpoint` доступні в аудиті.
- `ContinueService` та `AutonomousProgressionService` блокують звичайне
  виконання для `PREPARED/QUEUED/RUNNING/SECURITY_REVIEW` і завершальних
  станів до `RELEASED`.
- Дублікат постановки в чергу відхиляється до Messenger, повторна доставка
  повідомлення не створює новий AgentRun.
- Історичний superseded Security-звіт використовується тільки як джерело
  findings, не як схвалення до розробки.
- Справжній deployment/production rollback, HTTP cache headers, identity
  edge cases та security browser tests залишаються окремими вимогами
  `SEC-001…003`, не замінюються цим механізмом.

## Операційні обмеження

Підготовка коду, успішний CI та merge **не означають**, що конкретний
production workflow уже був відновлений або що Security findings
закрито. Перевіряти фактичні AgentRun, HTTP/cache та рішення потрібно
на розгорнутому production після окремої операторської дії.
