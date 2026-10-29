---
title: Capability Runtime Binding Witness — доказ зареєстрованого handler
description: Read-only перевірка реєстрації Domain Action/Query handlers та фактичного fail-closed Workflow worker gate.
status: implementation-draft
updated: 2026-10-30
kind: operations
---

# Runtime Binding Witness: реальна реєстрація та межі сертифікації

## Яку проблему усунуто

Попередній `CanonicalCapabilityGateway` повертав `binding_witness=ACTION_HANDLER_REGISTERED` на підставі tenant status `workflow_approval_required`. Хоча upstream resolver уже перевіряв DomainRegistry, ця ознака не була **незалежним** показником зареєстрованого виконавця. Крім того, інтерфейс не показував різницю між зареєстрованим Action handler та production-допуском воркера.

Тепер `CanonicalCapabilityGateway` отримує **той самий** `DomainModuleRegistry` і `ModuleActionExecutionGate`, які використовує канонічний `ActionExecutor`. Для `action:*` перевіряються власник `ownerOfAction`, реальний об'єкт handler у `actionHandlerMap` та `supports`. Для `query:*` і `command:*` перевіряється реєстрація існуючого `CapabilityInvocationAdapterInterface`. `read:*` залишаються `WORKFLOW_READ_VERIFIER_NOT_CERTIFIED` до окремої звірки evidence-порту.

`ModuleActionExecutionGate::workflowAdmissionMode()` є read-only introspection самого фактично ін'єктованого gate; значення:
- `WORKER_DISABLED_FAIL_CLOSED`: конкретний admission реалізує `FailClosedWorkflowAdmissionMarkerInterface`, тому Workflow-owned Action worker вимкнений;
- `WORKER_ADMISSION_NOT_INSTALLED`: admission немає;
- `WORKER_ADMISSION_REGISTERED_NOT_CERTIFIED`: є інший admission, але це не підтверджує правильне виконання чи безпечний cutover.

## Розділені виміри

| Вимір | Джерело | Що означає |
| --- | --- | --- |
| Typed definition | Domain `module.php` / CanonicalCapabilityCatalog | Оголошений контракт і digest |
| Handler witness | Наявні DomainRegistry / Capability adapters | Підтверджена реєстрація у **поточному контейнері** |
| Tenant admission | ActiveModuleResolver, permissions, canonical Gateway | Можливість звернення конкретного менеджера |
| Workflow worker mode | Сам ModuleActionExecutionGate | Fail-closed або інший непідтверджений admission |
| Business evidence | Native Domain readback + окреме Goal evaluation | **Не входить** до цього аудиту |

Звіт `GET /api/v1/capabilities/bindings` і `GET /api/v1/capabilities/{id}/readiness` тепер містить `binding_witness_issue` та `workflow_worker_admission_mode`. Ніякого іншого шляху запуску не додано. `worker_execution_certified` залишається `false`.

## Перевірка

~~~bash
php tests/unit/cos_capability_runtime_binding_witness.php
php tests/architecture/cos_capability_runtime_witness_authority.php
~~~

Тести з фальшивим, але **реально зареєстрованим** Property Action handler перевіряють, що witness з'являється тільки при збігу ownership та `supports`. Окремо перевірено відсутність реєстру, відсутній QueryBus adapter, вимкнений tenant module та стан fail-closed worker gate. Жоден тест не викликає handler.

## Операторський CLI для кожного runtime-контейнера

`php symfony/bin/console cos:capability:runtime:witness` завантажує реальні DI-сервіси того самого Symfony runtime і повертає машинний JSON із кожним typed contract, digest, owner та наявністю Action handler. Жодного тестового Action не виконує. Для окремої фази приймання доступний суворий режим:

~~~bash
php symfony/bin/console cos:capability:runtime:witness --require-action-bindings
~~~

У цьому режимі **відсутній handler спричиняє ненульовий exit code**, але сам звіт ніколи не позначає `worker_execution_certified=true`. Для `query:`, `command:` та `read:` CLI чесно повертає `NOT_EVALUATED_IN_THIS_COMMAND`; їхня реєстрація перевіряється через `/api/v1/capabilities/bindings` або окремі Domain evidence adapters.

Оператор повинен зберегти звіт **з кожного** вебконтейнера і worker-репліки разом із незалежно перевіреним release SHA, deployment identity, runtime config і результатами permission/Approval/Receipt тестів. CLI не визначає SHA автоматично та не стверджує, що інші replicas мають той самий образ.

## Наступний production acceptance

Щоб підтвердити **всі** реальні workers, необхідна додаткова процедура оператора: deployment SHA, інвентар усіх replicas, ізольований smoke на кожній, перевірка реального Approval/Policy/Receipt із MySQL, fault injection, native idempotency, незалежні підписи. `WORKER_ADMISSION_REGISTERED_NOT_CERTIFIED` не є дозволом увімкнути worker. Значення `WORKER_DISABLED_FAIL_CLOSED` наразі очікуване, бо production gate свідомо блокує Workflow-owned execution.

До окремого перевіреного production cutover **не змінювати** DI `$workflow: '@App\Persistence\Workflow\FailClosedWorkflowActionWorkerAdmission'`. Не плутати зелений CI реєстрації з фактичним Golden 50 business outcome.
