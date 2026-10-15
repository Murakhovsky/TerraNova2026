---
title: Контрольоване відновлення перевірки безпеки Engineering
description: Одноразове відновлення Architect та незалежний Security Review із ручним підтвердженням.
status: active
updated: 2026-10-15
kind: guide
---

# Контрольоване відновлення Engineering після блокування

## Передумови

Workflow у статусі `BLOCKED`, останній Architecture Decision відхилений,
історичний звіт Security Specialist містить невирішені findings.
Відкритих Human Gate та активних AgentRun немає, доступ до репозиторію
працює. Для задачі головної сторінки перевіряються `SEC-001…003`.

## Порядок дій

1. Розгорнути перевірений код із `main`.
2. Відкрити Engineering feature як Manager і переглянути Security findings,
   прив'язку звіту до revision та preflight.
3. У секції адресного відновлення підтвердити
   `RECOVER_SECURITY_FINDINGS`. Захищений CSRF POST створить одноразовий
   дозвіл і надішле повідомлення до наявного immediate worker.
4. Worker перевірить snapshot, під lock переведе `BLOCKED`
   у `ARCHITECTURE_PENDING` і виконає одну логічну спробу Architect,
   із максимум одним додатковим evidence-раундом.
5. За схваленої архітектури проведе одну незалежну перевірку
   Security Specialist. Результати зберігаються, після чого процес
   знову паркується в `BLOCKED` без автоматичного Developer.
6. Лише після незалежного Security `APPROVED`, прив'язаного до точного
   поточного Architecture Decision, можна окремо підтвердити
   `RELEASE_AFTER_SECURITY_REVIEW` і перейти до Developer.
   Стандартний бюджет агентів при цьому залишається чинним.

## Запобіжники

Дубльована доставка команди не створює другого запуску LLM.
Повторне використання того самого Security Review для нового
одноразового дозволу заборонено. Історичний звіт не вважається
автоматично чинним погодженням. Бюджет, історія AgentRun, токени
й аудиторський журнал не скидаються. Відновлення можливе лише після
явного підтвердження авторизованим Manager.

## Перевірки

Контрактні тести: `engineering_controlled_security_recovery.php`,
`engineering_feature_ui.php`, `engineering_web_async_execution.php`
і `engineering_golden_workflow.php`. Реальний production workflow
не запускається автоматично під час розгортання.
