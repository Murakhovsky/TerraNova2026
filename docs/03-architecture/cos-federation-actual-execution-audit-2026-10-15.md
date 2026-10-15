---
title: COS Federation — фактичний аудит виконання
description: Перевірка актуального коду COS, дублювання технічних Run/Step, джерел стану та перешкод безпечній консолідації.
status: active
updated: 2026-10-15
kind: architecture
---

# Фактичний аудит джерел виконання COS

**Перевірений коміт:** `a8b2cd5cb921ef96dacaf648af7e5f5767937e4a`, PR #346, гілка `feature/cos-federation-foundation-phase1-20261008`.

**Межа перевірки:** вихідний код GitHub, реєстрація залежностей, контракти та результати наявного CI. Прямої перевірки production-БД, реального трафіку і deployment не проводилося. Це не повний динамічний граф викликів.

## Підтверджений фактичний стан

1. **Спільний Workflow:** `DoctrineWorkflowExecutionStore` вже записує `cos_workflow_runs/steps`. Присутні tenant-bound create, CAS, claim/checkpoint, receipts та recovery. Однак `WorkflowExecutionCoordinatorInterface` ще не став єдиною активною точкою виконання Federation.
2. **Goal / Federation:** `FederationRunController` досі викликає `FederationSequentialOrchestrator`. Через `FederationGoalStore` та `FederationRunFinalizer` зберігається другий, самостійний технічний стан `cos_federation_runs/steps`. Це не лише read projection. Goal, Plan і Outcome як окремі бізнес-сутності необхідно зберегти.
3. **Часткова консолідація:** `FederationStepDependencyGraph` делегує `WorkflowDependencyGraph`; `FederationStepCursor` делегує `WorkflowStepScheduler`; `ExecutionRunPolicy` делегує `DurableWorkflowTransitionPolicy`. Це повторне використання вже працює, але життєвий цикл Run/Step не об'єднано.
4. **Engineering:** `EngineeringCanonicalTechnicalExecutionGateway` безпосередньо пише в `cos_workflow_runs/steps` для дозволених AgentRun замість виклику загального Store API. Бізнес-етапи Engineering, незалежні перевірки та людські погодження повинні залишитися у власному контексті. `EngineeringCanonicalTechnicalExecutionAdmission` перевіряє shadow parity, але не означає завершений cutover.
5. **Безпека виконання:** наявні `DoctrineWorkflowActionWorkerAdmission`, `WorkflowPredecessorReceiptVerifier` та адаптери нативних доказів. Типові загальні реалізації claim/receipt у `services.yaml` залишаються fail-closed. Їх не можна вилучати під час міграції.
6. **Порівняння даних:** `DoctrineWorkflowShadowParityReader` і `cos:workflow:shadow-audit` виконують read-only звірення. Повний доказ паритету для всіх реальних tenant/Run відсутній. У попередньому реєстрі дублювання 20 записів із `NOT_STARTED`, а 25 сценаріїв parity мають `NOT_IMPLEMENTED`.
7. **Перевірки:** на базовому коміті аудит встановив успішні `CI`, `Runtime`, `Frontend`, `Documentation`. Це не доказ перенесення production-даних чи відсутності подвійних writer.

## Матриця відповідальності за виконання

| Контур | Наявні записи | Цільове рішення |
| --- | --- | --- |
| Kernel Workflow | Переважно in-memory об'єкти `WorkflowExecution` | Adapter або зняття з виконання після перевірки consumers |
| Федеративні Goal | `cos_federation_runs/steps`, writer `FederationGoalStore` та `FederationRunFinalizer` | Лише Goal/Plan/Outcome у Goal Management; технічні Run/Step у спільному Workflow |
| Canonical Workflow | `cos_workflow_runs/steps`, `DoctrineWorkflowExecutionStore` і `DoctrineWorkflowRecoveryManager` | Єдине технічне сховище з перевірюваним execution admission |
| Engineering AgentRun | Canonical Run/Step через власний прямий SQL та окремий бізнес-Workflow | Загальний Store API для технічного виконання, незмінні бізнес-етапи та human gates |

## Реалізований захист від нових дублікатів

У цьому інкременті впроваджено правило `FED-INV-04`, реєстр `resources/architecture/cos-execution-authority-freeze.json` і CI-перевірку `tests/architecture/cos_federation_execution_authority_freeze.php`. Перевірка шукає нових прямих SQL/DBAL writer для чотирьох таблиць Run/Step та нові загальні механізми Federation execution у runtime-коді. Існуючі writer внесені до зафіксованого переліку технічного боргу. Окремо виключена одна транзакційна smoke-команда, яка створює синтетичні тестові записи.

Це **статичний регресійний бар'єр**, а не автоматична міграція, не доведений повний call graph і не дозвіл видаляти legacy таблиці.

## Обов'язкова послідовність завершення консолідації

1. Реалізувати та підключити один durable `WorkflowExecutionCoordinatorInterface` до спільного Workflow Store, Action/Agent/Tool, Policy, Approval та Queue. Не створювати окремий Federation worker.
2. Перевірити всіх runtime consumers, web/CLI/queue, власників DB writes та єдину прив'язку tenant+Plan до Run.
3. Зробити additive ID mapping, read-only backfill старих Run/Step, незалежну перевірку receipts, точну звірку станів і карантин сумнівних записів.
4. Передати право запису через fence/drain для окремих груп tenant/Plan. Жодних dual writes, подвійних Action dispatch чи автоматичних повторів невизначеного зовнішнього ефекту.
5. Замінити `FederationSequentialOrchestrator` тонким Goal-to-Workflow adapter, вимкнути legacy writer, прибрати інертний міст лише після доказового cutover.
6. Перенести прямі Engineering SQL-операції до загального Store API, не втрачаючи AgentRun artifacts, human approval, review/QA і transition history.
7. Перевірити Golden 50 та інші Domains через реальні зареєстровані capabilities і підтверджені бізнес-факти. Перед production acceptance потрібні MySQL failure-injection, fleet-wide parity та процедура відновлення.

**Статус:** консолідація триває; нормативну федеративну модель ще не можна вважати впровадженою повністю.
