---
title: Незмінні контракти Capability у Goal Plan — контрольований запуск
description: Операторські правила перевірки контрактів, сумісності історичних планів і допуску Workflow.
status: implementation-draft
updated: 2026-10-25
kind: operations
---

# Незмінні контракти Capability у Goal Runtime

## Призначення та межі реалізації

Поточний інкремент розширює наявні **Domain-owned Module Capability Contracts** і **Platform Workflow**. Нові плани через `FederationGoalStore::proposePlan` викликають `GoalPlanValidator::validate(..., true)`. До кожного нормалізованого кроку додаються `contract_digest` та `input_digest`. Ці значення входять до незмінного JSON плану, який перевіряється чинним механізмом погодження й хешування.

Новий Action executor, SQL writer, Scheduler, EventBus, машинний дозвіл або альтернативний Recovery Engine **не створено**.

## Матриця поведінки

| Стан | Правило системи |
| --- | --- |
| Новий запропонований Goal Plan | Фіксується digest контракту, JSON-схем входу/виходу та нормалізованого input |
| Контракт змінився до погодження | Погоджений раніше Plan не перенаправляється на новий контракт автоматично |
| Зміна digest перед запуском Run | Виконання блокується ще до створення технічного Run |
| Зміна після запуску Run | Перед новим Step повертається `manual_reconciliation_required` без нового Action |
| Погодження claim / worker | Підготовлені admission-компоненти перевіряють pinning незалежно від UI |
| Старий погоджений Plan без digest | Не переписується; чинні перевірки версій, binding і Approval залишаються |
| Частково заповнений Plan | `GoalCapabilityPinGuard` блокує виконання |
| Action завершена, але output некоректний | Не робити blind retry; потрібна незалежна перевірка native результату |
| Goal achieved | Потрібен окремий доказ бізнес-результату з відповідного Domain |

## Головне обмеження production

У `symfony/config/services.yaml` чинний `Kernel\Action\Service\ModuleActionExecutionGate` отримує `App\Persistence\Workflow\FailClosedWorkflowActionWorkerAdmission`. Підготовлений `DoctrineWorkflowActionWorkerAdmission` **не підміняє** цей захист у поточному PR. Наявність нових перевірок digest або позитивний unit test не активують зовнішній Action worker.

Змінювати production DI binding заборонено до незалежного підтвердження всіх approval/worker/receipt шляхів, tenant-паритету, MySQL crash/failover тестів, відновлення та підпису двох уповноважених операторів.

## Ручна перевірка

На checkout з установленими root Composer dependencies:

~~~bash
php tests/unit/cos_capability_contract_snapshot.php
php tests/unit/cos_goal_capability_pinning.php
php tests/architecture/cos_goal_capability_pin_authority.php
php tests/unit/canonical_capability_gateway.php
php bin/cos-capability-census.php --strict
~~~

Команда `cos-capability-census.php` аналізує **тільки декларації та локальні JSON-схеми**. Вона не підтверджує живі DI handlers, права tenant, виконання Action або досягнення Goal.

Інтерфейс `/workspace/goals` показує `PINS_PRESENT_NOT_ATTESTED`, `LEGACY_NO_PINS` чи `INCOMPLETE_PINS_BLOCKED` як операторську діагностику. Сам факт наявності digest **не дорівнює** перевіреному контракту або дозволу на виконання.

## Незавершені контрольні етапи

1. Незмінний архів історичних версій контрактів і явне підключення сумісних старих handlers. Невідому версію або digest блокувати.
2. Live-інвентаризація реєстрації bindings усіх Domains, політик tenant і перевірка на конкретному deploy SHA.
3. Допуск Action worker лише після повної перевірки approval, receipt, recovery та зовнішніх ефектів; production залишається fail-closed.
4. Валідація вихідних JSON-схем та незалежних native доказів для **керованих** Actions (поточний Gateway перевіряє лише свої прямі Query/Command).
5. Підключення AI/CRON/Event до запропонованих Plans без надання нового права виконання.
6. Наскрізний Golden 50: 50 native Candidates, 50 Leads, 50 Documents, 200 погоджених дочірніх Actions та незалежна збережена оцінка Goal.

Документ описує технічні межі реалізації, **а не підтверджений production acceptance**.
