---
title: Сертифікація runtime bindings і перевірка результатів Capability — P0
description: Операторський маршрут приймання CL-04, CL-06 і Goal Plan preflight без зміни execution authority.
status: implementation-draft
updated: 2026-10-26
kind: operations
---

# Capability Runtime: підключення, вихідні дані та Goal Plan

## Обсяг інкременту

Реалізацію розділено на три контрольовані частини без нового executor:

1. **Read-only binding audit** через `GET /api/v1/capabilities/bindings`. Відповідь tenant-scoped і дозволена чинному менеджеру. Вона містить declared/typed/schema-valid/bound/unavailable агрегати, але завжди `certified=false`, `worker_deployment_verified=false`, `native_business_evidence_verified=false`. Перевірка на рівні request-container **не є** сертифікацією всіх worker replicas або production deployments.
2. **Governed Action technical receipt**. `DoctrineWorkflowActionReceiptVerifier` повторно підтверджує pinned Contract/Step через `GoalCapabilityPinGuard` і вимагає фактичний JSON `cos_action_attempts.result` від єдиної завершеної першої спроби. Output валідується за pinned `CapabilityContract.outputSchema`, перш ніж підтвердити технічний receipt. Для старих планів без digest збережено чинну спадкову модель погодження й доказів. Змішані та частково pinned плани блокуються.
3. **Goal Capability Plan preflight** через `POST /api/v1/capability-plans/validate`. Авторизований власник Goal передає JSON із `goal_id` та `steps` (1–50). Сервіс використовує чинні `FederationGoalStore`, `FederationCapabilityBindingResolver` і `GoalPlanValidator` з pinning, DAG/schema/permission/binding перевірками. Жодного запису Plan, Run, Approval, Action чи side effect.

## Вхідні дані Goal preflight

Приклад лише для ілюстрації форми запиту; ідентифікатори бізнес-об'єктів мають реально існувати.

~~~json
{
  "goal_id": "goal-реальний-ідентифікатор",
  "steps": [
    {
      "id": "followup",
      "capability_id": "sales.create_task",
      "capability_version": "1.0.0",
      "input": {
        "target_type": "deal",
        "target_id": "існуюча-угода",
        "parameters": {
          "title": "Зателефонувати клієнту",
          "due_in_minutes": 60
        }
      }
    }
  ]
}
~~~

Сервер бере актора/tenant із сесії; CSRF обов'язковий. Саме сервер визначає наявність виконуваного handler. Навіть позитивний preflight повертає `proposal_only=true`, `executed=false`, `persisted=false` та `authorization_granted=false`.

## Вихідний receipt та межі доказів

Для нових pinned Plans тільки валідний output із `cos_action_attempts.result` може пройти перевірку, прив'язану до конкретних `capability_id@version + contract_digest`. Відсутній, некоректний, завеликий або несумісний output => помилка proof; залежні Steps і незалежна Goal evaluation не можуть вважати його надійним.

**Не плутати:** schema-valid technical output, звірка нативних об'єктів Growth/Sales/Documents та Goal Outcome — три різні гейти. Ця зміна не перетворює `Action COMPLETED` на `Goal ACHIEVED`. Native readback належить відповідальному Domain та окремим evidence providers.

Об'єкти з output-полями `{"const": false}` чи `{"enum":[...]}` тепер підтримуються у вкладених властивостях обмеженого локального JSON Schema профілю. Сторонні `$ref`, невідомі keywords та зовнішні URL не допускаються.

## Тести

| Тест | Що підтверджує |
| --- | --- |
| `tests/unit/cos_golden50_governed_action_output.php` | Pinned Growth/Document schema profile, обов'язкові native status enum/const, відсутність synthetic outcome |
| `tests/integration/workflow_action_receipt_mysql.php` | Додано pinned Action із збереженим output; невірний тип, відсутність output і підміна digest відхиляються без повторної Action |
| `tests/architecture/cos_capability_receipt_preflight_contract.php` | Gateway/DI/Routes/Verifier пов'язані з існуючими власниками, execution не активується |
| `tests/unit/canonical_capability_gateway.php` | Tenant runtime binding census чесно повідомляє `certified=false` |
| CI Fast та Runtime MySQL | Контрольна перевірка на останньому SHA (після запуску) |

## Незавершені умови приймання

- **Production Action worker:** у `ModuleActionExecutionGate` і далі `FailClosedWorkflowActionWorkerAdmission`. Немає автоматичного cutover.
- **Повна сертифікація bindings:** потрібні deploy SHA, конкретні worker instances, DI smoke, tenant policies, операторський підпис. Поточний API — лише свідчення реєстрації у request container.
- **Native Goal evidence:** потрібні окремі Domain evidence/readback перевірки кожного результату.
- **AI Planner:** реалізований детермінований та безпечний preflight запиту, але не LLM-based генерація плану і не машинний дозвіл виконання.
- **Golden 50:** реальний 50/50/50 та 200 незалежних Action approvals ще не підтверджені.

Після релізних CI PASS ці зміни залишаються окремим проміжним пакетом CL-04/06/07; повна сертифікація Reliable Core вимагає окремих end-to-end і failover acceptance.
