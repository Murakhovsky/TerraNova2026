---
title: "Збереження стану федерації COS"
description: "Схема даних цілей, запусків, доказів та безпечного відновлення."
status: active
updated: 2026-10-08
kind: architecture
---

# Збереження цілей та виконання COS

Doctrine-міграція Version20261008142500 додає лише нові таблиці платформи:
цілі, незмінні версії специфікації, плани, запуски, кроки, оцінки результату
та користувацькі стани інтерфейсу. Дані ізолюються за organization_id.

## Правила

- Запис кроку має складений ключ organization_id / run_id / step_id.
- idempotency_key унікальний у межах організації.
- Кроки з невизначеним результатом зовнішнього виклику не запускаються автоматично повторно.
- Підтвердження фінансових чи зовнішніх операцій залишаються у вже чинному механізмі Policy/Approval.
- Завершення Workflow і досягнення бізнес-результату залишаються різними фактами.
- Міграція не змінює бізнес-таблиць Domain. Повернення функції до попередньої версії відбувається вимкненням нового сценарію, а не видаленням історії.

## Обмеження поточного етапу

Схема й модель переходів є підґрунтям для durable execution, але не замінюють
існуючий Workflow Engine та не забезпечують автоматичну міждоменну диспетчеризацію.
Фактичне виконання має бути підключене через чинні контракти та підтверджене інтеграційними тестами.


## Контроль авторизації ExecutionRun

Міграція Version20261008193000 забезпечує унікальність (organization_id, plan_id)
у таблиці запусків. Для повторної спроби потрібно відновлювати існуючий run
із його незмінними idempotency keys, а не створювати другий.

FederationPlanApprovalEvidenceReader виконує незалежну перевірку:

- Action з канонічним типом cos.federation.plan.approval має належати цьому tenant і цьому plan_id.
- Action має перебувати в QUEUED і вимагати APPROVAL_REQUIRED. Цей стан сам по собі ще не є достатнім.
- Параметри Action зобов'язані точно збігатися з goal_id, plan_id, версією Goal та SHA-256 *збереженого* plan_json.
- Останнє рішення Policy evaluation має бути APPROVAL_REQUIRED.
- Потрібне рівно одне підтверджене людське погодження: без закінчення терміну, від ідентифікованого користувача, не самого ініціатора.
- FederationWorkflowPreflight і FederationGoalStore.startApprovedRun використовують цей доказ замість списків «дозволених дій» із запиту.

GoalPlanApprovalRequestFactory формує детермінований, ідемпотентний
ActionProposal, але свідомо не передає його в ActionPolicyService.
FederationPlanApprovalCoordinator містить підключення до ActionPolicyService,
але перед викликом перевіряє зареєстрованого власника та handler дії,
активність його модуля в tenant, незмінну поточну версію Goal,
справжню належність ініціатору та явне рішення Policy Engine
APPROVAL_REQUIRED. Без виконання цих умов жодного Action не створює.
Навіть після майбутньої активації handler повинен повторно перевірити
людське погодження, щоб AUTO-policy ніколи не дала обхід.


**Умова активації:** `FederationDomainModule` тепер зареєстрований
у DomainModuleRegistry разом з `FederationPlanApproveHandler`, але має
`enabled_by_default=false`. `ModuleActionExecutionGate` забороняє запуск
для tenant без явної активації. Навіть після активації
`FederationPlanApprovalCoordinator` допускає створення дії лише за
наявності явної політики `APPROVAL_REQUIRED`; обробник ще раз перевіряє
незмінний план, власника, рішення Policy та незалежне людське погодження.
Реальні tenant не активувалися в рамках цього PR. Виробниче
міждоменне виконання залишається за окремим release gate.

Workflow lifecycle projection не доводить досягнення бізнес-цілі.
Outcome evidence поки є посиланнями на факти, їх потрібно перевіряти
в авторитетних read models відповідних Domains.


## Безпечне виконання Workflow без побічних дій

FederationReadOnlyWorkflowRunner викликає наявний Kernel WorkflowEngine
після того, як FederationWorkflowPreflight перевіряє погоджений план
конкретної організації, канонічну дію Action, рішення Policy та незалежне
погодження людиною через Approval.

Перший виконуваний сценарій підтримує **лише один SystemStep** з операцією
`federation.read_only.checkpoint` та порожніми параметрами, без переходів
і конфігурації. HumanStep, AgentStep, ToolStep, інші SystemStep,
багатокрокові процеси, зовнішні та фінансові операції блокуються.
Обробник системних кроків додатково перевіряє ідентичність операції
та відсутність аргументів.

Для кожного погодженого плану може існувати лише один збережений запуск.
Перед викликом WorkflowEngine система переводить запуск у стан running
і ексклюзивно резервує крок. Успішне завершення атомарно записує ідентифікатор
виконання Workflow, стан кроку та контрольну точку до таблиць Федерації.
Якщо процес перервався після резервування кроку, автоматичний повторний запуск
заборонено: необхідна ручна перевірка.

**Завершений Workflow не означає досягнення бізнес-цілі.** Підтвердження
критеріїв Goal та перевірка доказів залишаються окремими операціями.

Транзакційний MySQL smoke-тест перевіряє справжній WorkflowEngine на
штучних записах погодження, які після тесту відкочуються. Він охоплює
запис контрольної точки, ізоляцію організацій, заборону повторного запуску
та відхилення небезпечних типів кроків. Цей тест не активує production
Action handler для погодження планів Федерації.

## Типізоване виконання між доменами

Перший Domain-owned бізнес-контракт: `sales.create_task`. Він посилається
на вже існуючий `Sales` Action handler `sales.create_task`, а не вводить
нового виконавця. `FederationCapabilityBindingResolver` перевіряє власника
дії, наявність обробника, активність модуля Sales у tenant та дозвіл
поточного менеджера. Службова дія `federation.plan.approval` не може
бути кроком бізнес-плану.

Для зовнішньої дії `GoalPlanValidator` вимагає `input`,
перевіряє його за локальною версіонованою JSON-схемою з обмеженим
набором підтримуваних правил та вміщує у незмінний `plan_json`.
Схвалення захищає також SHA-256 саме цього знімка. Некоректні,
завеликі або незадекларовані параметри відхиляються.

`FederationApprovedActionIntentFactory` ізольовано створює
`ActionProposal` з уже погодженого кроку і детермінованого
ключа ідемпотентності. Він перевіряє повторно Action/Policy/Approval,
активну capability та стан Run/Step, але **не ставить дію в чергу**.
Окремий безпечний submit/reconciliation adapter ще необхідний:
він повинен гарантувати незалежне погодження конкретної Sales-дії,
перевірку дійсної політики перед виконанням і недопущення
автоматичного повторного зовнішнього ефекту після невизначеного результату.


## Перевірка зовнішніх результатів і завершення запуску

Після виконання канонічної Action оператор може звірити її результат через
FederationExternalActionReceiptReconciler. Один лише статус COMPLETED не є
доказом: FederatedActionAdmission додатково звіряє незмінні параметри
затвердженого плану, організацію, останнє рішення Policy, незалежне
погодження людиною та **рівно одну успішну спробу** Action.

Невизначені повторні спроби, підміна отримувача або параметрів Action
залишають Step незавершеним із вимогою ручної перевірки. Звірка результату
не викликає обробник Domain і не запускає повторно зовнішню операцію.

FederationRunFinalizer може атомарно перевести багатокроковий Run із
running до completed лише після перевірки, що кожен Step завершено й
має результат. Для кожного зовнішнього Step під час фіналізації повторно
перевіряється канонічна Action, незмінний ідемпотентний ключ і
підтвердження незалежною людиною. Версія Run перевіряється через
оптимістичний контроль конкурентних записів. Непідтверджені, часткові
чи невизначені завершення блокуються.

**Семантика:** завершений Run означає виконання погодженого технічного
плану, а не виконання бізнес-критеріїв Goal. Останні потребують
підтверджених даних з авторитетних read models відповідних Domains.

Нові перевірки у MySQL smoke-тесті охоплюють одноразовість зовнішньої
операції, підміну її цілі, ізоляцію tenant, повторну звірку завершеного
результату та одноразову фіналізацію Run.


## Послідовна координація міждоменних Action (перший вузький сценарій)

`FederationSequentialOrchestrator` здійснює **один** перехід
погодженого Run за один виклик. Порядок береться з незмінного
`cos_federation_plans.plan_json.steps`, а не з алфавітного порядку
записів у БД. `FederationStepCursor` перевіряє повну відповідність
ідентифікаторів, версій, сторін контракту та станів. Сьогодні допущена
лише лінійна топологія зовнішніх `action:` capabilities, зокрема
`sales.create_task`. Паралельні гілки, довільні Agent/Tool та
фінансові дії не активовано.

Під час кожного переходу система перевіряє tenant, роль менеджера,
увімкнений модуль Federation, поточну специфікацію Goal, незмінність
затвердженого Plan та незалежне канонічне погодження. Далі:

1. `pending Run` переходить у `running`, не створюючи Action.
2. `pending Step` передається до `FederationExternalActionSubmission`,
   який вимагає `APPROVAL_REQUIRED` і створює канонічну Action із
   **окремим людським погодженням**.
3. `claimed Step` лише звіряє результат Action; жодного повторного
   dispatch. Поки погодження або робота не завершені, повертається
   стан очікування.
4. Перед наступною Action **кожен попередній completed Step**
   повторно перевіряється за реальним Action receipt, незмінними
   параметрами, Policy, Approval та одноразовою спробою виконання.
   Підроблений або невизначений результат блокує подальший рух.
5. Коли всі кроки підтверджені, `FederationRunFinalizer` переводить
   Run у завершений стан без заяви про досягнення бізнес-цілі.

Менеджер може явно керувати цим циклом через CSRF-захищені маршрути
з сесійною tenant-авторизацією:

- `POST /api/v1/federation/runs/start` із полями `plan_id`,
  `approval_action_id`, `csrf_token`.
- `GET /api/v1/federation/runs/{runId}` для станів і квитанцій без
  виведення внутрішніх ідемпотентних ключів.
- `POST /api/v1/federation/runs/{runId}/advance` із
  `approval_action_id`, `csrf_token`.

Модуль `federation` за замовчуванням вимкнений для організацій.
Ці ендпоїнти **не** запускають фоновий цикл, не обходять Policy
та не здійснюють повторної зовнішньої спроби після збою.
Тест транзакційно перевіряє два кроки Sales із різними target,
людськими погодженнями, алфавітно інвертованими ID,
відмову під час підміни попереднього результату, ізоляцію tenant
та завершення без replay.

**Незакриті напрями:** branching/DAG, відкладена черга з recovery,
інші Domain capabilities, довірена верифікація Goal Outcome
за бізнесовими read models, повний Adaptive Workspace, production
enablement та фінальне end-to-end приймання.


## Recovery Inspector, manual incident escalation and receipt sweep

`FederationRunRecoveryService` has a tenant-scoped, read-only diagnostic
`inspect()` operation and a **receipt-only** `reconcileVerified()` operation.
An opted-in tenant manager can use:

- `GET /api/v1/federation/runs/{runId}/recovery` to see per-step recovery
  classification and attention/recoverable counts.
- `POST /api/v1/federation/runs/{runId}/reconcile`, with the normal session
  CSRF token, to apply **only** already-completed, independently attested
  canonical Action receipts.

`FederationRecoveryClassifier` classifies pending, claimed, completed,
ambiguous, orphaned and failed states, including missing Action after
the Step claim, independent human approval pending, queued, running,
stale execution, rejected Action, multiple attempts, and an Action marked
COMPLETED without one successful attempt. No guess is considered a receipt.
`started_at` on the canonical Action is used for RUNNING worker age;
a long queue/approval wait is not mistaken for worker execution time.

**Hard stop:** the recovery sweep never creates, resubmits, requeues or
executes an Action, and never advances to the next Plan Step.
A claimed Step with no durable Action can reflect a crash between Step
claim and Action submission, so it stays reserved. An Action with more
than one execution attempt, forged input, an expired human approval or
an uncertain outcome requires manual incident reconciliation.

Step completion now locks the owning Run before mutation and rejects
late execution reports after terminal Run transitions. Independently,
the receipt reconciler checks that claimed Steps belong to a running
Run; it can still re-attest historical completed receipts read-only.

Integration smoke covers expected human waiting, rejected Action
escalation, tampered predecessor detection, completed Action
reconciliation (exactly once), terminal Step write denial and tenant
isolation. The pure classifier unit suite also covers orphaned claims,
stale workers, duplicate attempts and attempted requeue.

**Limit:** this is bounded, manager-triggered reconciliation, not yet
an autonomous watchdog or a full recovery workflow with audited human
decisions. There is no implied permission to reissue failed external
business actions.
