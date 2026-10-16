---
title: "Планування збережених цілей: малі сценарії та Golden 50"
description: "Покрокова інструкція зі створення плану цілі, незалежного погодження, канонічного запуску, перевірки прогресу та результату."
status: draft
kind: workflow
updated: 2026-10-16
---

# Goals: Golden 50 та малі сценарії (P0/P1, 2026-10-16)

## Бізнес-мета

Надати менеджеру можливість після збереження цілі підготувати обмежений, перевірюваний план, передати його на незалежне погодження та побачити виконання в єдиному Platform Workflow. Жодна чернетка не є дозволом на зовнішню дію.

## Учасники

- **Власник цілі:** авторизований менеджер створює ціль, планує дії та ініціює запуск.
- **Незалежний погоджувач:** інша уповноважена особа схвалює план і кожну зовнішню дію, де потрібне погодження.
- **Sales або Growth:** доменний власник конкретного Action та джерело підтвердженого бізнес-результату.
- **Platform Workflow:** єдиний технічний власник нових Run і Step, квитанцій та контрольованого відновлення.

## Карта коду

- `symfony/src/Web/Federation/GoalsWorkspaceController.php`: захищені форми і результати.
- `app/Platform/Orchestration/Goal/FederationSmallTaskPlanFactory.php`: типізований план 1–3 дій.
- `symfony/src/Persistence/Federation/FederationSmallGoalPlanProposalService.php`: транзакційна пропозиція без запуску.
- `symfony/src/Persistence/Federation/FederationPlanApprovalCoordinator.php`: чинний Action/Policy/Approval.
- `symfony/src/Persistence/Federation/FederationCanonicalWorkflowBridge.php`: виключно канонічні Run та Step.
- `symfony/src/Persistence/Federation/FederationGoalStore.php`: версії цілей, плани, незалежні результати.

## Операторський потік

1. На `/workspace/goals` створіть вимірювану Goal. `sales.local_tasks_created` — найпростіший тест. Збереження **не запускає роботу**.
2. У збереженій Goal натисніть **Побудувати план**:
   - Sales: оберіть **1–3** послідовні операції `sales.create_task`, вкажіть **існуючі** Deal ID, назви та терміни. Це справжні Action зі схемною перевіркою, а не фіктивні CRM дані.
   - Golden 50: задайте існуючий, активний Growth Universe, слот та обмеження. Застосовується наявний `FederationDiscoveryPlanProposalService`, без створення 50 несправжніх лідів.
3. План має статус `proposed`. Натисніть **Запросити незалежне погодження**. Це створює канонічний `cos.federation.plan.approval` Action за чинними Policy, **не** схвалює план самостійно.
4. **Інший уповноважений користувач** приймає Approval, існуючий Action worker виконує Approval Action, і лише тоді Plan стає `approved`. Статус у Goals відображає стан `cos_actions` та Plan; кнопка «Запустити» не підтверджує Approval.
5. Натисніть **Запустити через Platform Workflow**, потім **Продовжити виконання** для операторських переходів. Дозволено тільки для організації, явно активованої `COS_FEDERATION_CANONICAL_ORGANIZATION`. Run/Step authority: `cos_workflow_runs/steps`. У `cos_federation_runs/steps` нові виконання **не записуються**.
6. Кожна зовнішня дія проходить власні Policy/Approval/Action gates. Завершення Workflow ≠ бізнес-результат. Остання незалежна оцінка Domain read model зберігається й показується на самій картці Goal.

## Параметри безпеки й деградація

- Створення плану серіалізується lock'ом Goal; однакові планові дії отримують детермінований `plan_id` і не створюють дублікати навіть при повторному POST. Для іншого набору дій plan_version монотонно збільшується.
- Уже збережені старі Sales Goal з порожнім списком дозволених дій отримують **нову версію** контракту лише після явного будування малого плану, лише якщо не існує жодного Plan. Збережений Golden 50 та його 5-capability reference не змінюється.
- Виконання лише через `FederationCanonicalWorkflowBridge`. Повторний запуск того самого Plan забороняється canonical store. Невизначений зовнішній результат не повторюється автоматично.
- UI показує читабельну категорію блокування: rollout, відсутній Action handler/модуль, неактивний Universe, Owner, Policy/Approval, застарілий Plan, ambiguity/reconciliation. Діагностичні exception, SQL і JSON не виводяться.
- Не всі бізнес-метрики мають готові Action-шаблони. Поки є лише **Sales task** та **Golden 50 discovery**, решта Goal — вимірювання без автоматичного виконання.
- Прогрес і результати читаються з Platform Workflow та незалежної Domain outcome evaluation. Обмежені historical Federation Runs залишаються read-only / receipt-only, без нового dispatch.
- **Acceptance зовнішнього середовища НЕ виконано цим комітом**: tenant rollout, живі Sales Deal ID/Growth Universe, створення Approval іншим користувачем, Policy для обох Action та фактичні worker receipts мають бути підтверджені на ізольованому staging і згодом production.

## Тести

`php tests/unit/federation_small_goal_plan.php` та `php tests/architecture/federation_goals_workspace.php`. Додатково необхідні наявні Federation MySQL/Workflow Action receipt та Golden 50 E2E suites. Факт зеленого синтаксичного тесту не доводить live approval або зовнішніх бізнес-результатів.
