---
title: "Операторське приймання сценарію Golden 50"
description: "Послідовність перевірки 50 унікальних кандидатів Growth, незалежних погоджень, лідів Sales, документів та оцінки бізнес-цілі."
status: draft
kind: workflow
updated: 2026-10-14
---

# Package A: операторське приймання Golden 50 (лише реальні дані)

**Статус:** інструкція / release gate, а не сертифікат. Проходження unit-тестів або MySQL smoke з відкачаними fixtures **не** дорівнює 50 реальним Sales Leads і 50 Documents.

## Бізнес-мета

Підтвердити наскрізний бізнес-результат: рівно 50 унікальних підтверджених кандидатів Growth, кожен із погодженим чотириетапним виконанням, власним Sales Lead та прив'язаним Documents, і незалежну перевірку Goal. Unit-тести не замінюють результат на тестовому tenant.

## Учасники

- **Менеджер tenant:** створює Goal, погоджує параметри вибірки, запускає перевірки та ініціює оцінку результату.
- **Незалежний погоджувач:** окремо погоджує кожний план та всі зовнішні Action.
- **Growth:** підтверджує походження й кваліфікацію кандидатів та виконує Sales handoff.
- **Sales:** єдиний власник запису Lead і доказу його створення.
- **Documents:** створює та прив'язує підготовлену пропозицію без надсилання чи підписання.

## Карта коду

- `app/Platform/Orchestration/Goal/FederationGolden50GoalProfile.php`: допустимі Action для Goal із ціллю 50.
- `symfony/src/Persistence/Federation/FederationCandidateFanoutService.php`: перевірка реальних даних Growth і пропозиція дочірніх планів.
- `symfony/src/Persistence/Federation/FederationGoalOutcomeAggregationService.php`: повторна незалежна звірка джерела, Action receipts, Sales і Documents.
- `symfony/src/Persistence/Federation/FederationVerifiedRunReadModel.php`: єдиний read-only evidence view для canonical Platform Workflow та історичних Federation Run. Подвійна authority для Plan/Run заборонена. Canonical Action перевіряється через незалежний `DoctrineWorkflowActionReceiptVerifier`, старі завершені receipts через frozen historical adapter. Це **не** dual-write, не новий scheduler та не operator sign-off.
- `app/Platform/Orchestration/Goal/FederationDiscoverySourceMatcher.php`: достовірність native Growth membership.
- `symfony/src/Web/Federation/FederationGoalAggregateController.php`: read-only агрегат і CSRF-guarded запис Goal evaluation.

## Вимірювана мета

- Один tenant-owned Goal, критерій `federation.verified_proposals`, оператор `at_least`, очікуване значення **50**.
- Підтверджені native Growth Account/Candidate/membership із п'ятдесятьма різними `candidate_id`, `account_id` і `external_key_hash`; кожен scored у Growth, джерело зі завершеного **approved Federation Discovery Action**.
- Кожен із 50 дочірніх Plan має рівно чотири Action: `growth.candidate.qualify` → `growth.handoff.prepare` → `growth.handoff.target.sales` → `documents.proposal.prepare`. Немає дублюючої дії створення Sales Lead: `growth.handoff.target.sales` викликає native Sales intake.
- Незалежний людський approver для **Plan та кожної Action**; збережені approval evidence і перша успішна спроба. Без self-approval, AUTO bypass або replay.
- **50** native Sales Leads, **50** template-backed Documents з relation `sales.lead`, без дублів, підписання чи відправлення.

## Передумови стенда

Використовуйте ізольований test/staging tenant, а не бойову базу клієнтів. У tenant мають бути ввімкнені `federation`, `growth`, `sales`, `documents`; політика кваліфікації має бути `active` із зафіксованою revision; шаблон документів `active` і з усіма змінними. Автор Goal має бути native numeric user. Потрібні **два різні уповноважені користувачі** для автора/виконавця і незалежного approver.

Growth Market Discovery повертає Account, але Candidate з'являється лише за умов Growth signal/opportunity. Відсутні 50 native scored Candidates або 50 перевірених champion-контактів означають **BLOCKED**, а не дозвіл підмінити їх синтетичними ID. Тестова документація та `example.test` не є реальними бізнес-кандидатами.

## Операторська послідовність

1. У `/workspace/goals` створити Goal із кодом `federation.verified_proposals` та значенням `50`. Сервер призначить лише п'ять дозволених Action capabilities. **Це не затвердження Goal/Plan і не виконання.**
2. Через POST /api/v1/federation/discovery/plans/propose (session CSRF) передати goal_id, universe_id, slot=1, limit=200. Маршрут перевіряє наявний tenant-owned enabled Sales-targeted Growth Universe, профіль Golden 50 та живий Action handler і створює ТІЛЬКИ запропонований immutable parent Discovery Plan. Слоти 1–10 дозволяють декілька окремо погоджених сканувань; повторне створення того самого Plan ID заборонене. Через POST /api/v1/federation/plans/{planId}/request-approval (CSRF) подати заявку на незалежне людське погодження Plan. Approval Action у pending не означає фактичного погодження. Після реального Approval запустити source Run через /api/v1/federation/runs/start з approval_action_id; окремо погодити Growth Discovery Action та завершити Run. Прямі фіктивні SQL-записи заборонені.
3. У `GET /api/v1/federation/fanout/preview` передати `goal_id`, `source_run_id`, `source_step_id`, `requested=50` або послідовно `10` і `40`, `policy_id`, `policy_revision`, `template_id`, `expected_value`, `recommended_play`, `recommended_action`. Має бути `ready=true`, `eligible>=requested`, жодного дубля source/Account/Candidate. GET не має побічних ефектів.
4. Тими ж полями зробити `POST /api/v1/federation/fanout/propose` із сесійним CSRF. Результат: **50 proposed immutable Plans**. Повторення з тими самими ідентичностями не створює додаткових планів.
5. **Окремо для кожного Plan**: запросити незалежний Plan approval через POST /api/v1/federation/plans/{planId}/request-approval з CSRF, дочекатися human decision; `POST /api/v1/federation/runs/start` із `plan_id` і `approval_action_id`, CSRF. Кожен `POST /api/v1/federation/runs/{runId}/advance` просуває **лише один** перехід. Action переходить у стан очікування людського погодження, а не виконується автоматично. Після worker execution перевіряти receipt і продовжувати до `completed`; негативний або неоднозначний результат відправляти на ручне `/recovery` та `/reconcile`, не автоматично повторювати side-effect.
6. `GET /api/v1/federation/goals/{goalId}/aggregate` у сесії manager: очікується `candidate_plans=50`, `verified_action_steps.qualify/prepare/handoff/proposal=50`, `native_sales_handoffs=50`, `native_proposals_prepared=50`, 50 окремих `sales_lead_id` та `document_id`, а для кожного позитивного результату `source_provenance_verified=true`. Лише `aggregate_state=ready_for_independent_goal_evaluation`, ще **не** `business_outcome_verified`.
7. Тільки після пункту 6 окремий оператор викликає `POST /api/v1/federation/goals/{goalId}/evaluate-aggregate` із CSRF. Успіх: новий durable запис `cos_federation_evaluations`, `result=satisfied`, `evidence_policy=native_goal_aggregate_v1`, `verified_candidate_count=50`, `business_outcome_verified=true`. Зберегти ID evaluation та Run/Lead/Document reference hashes як acceptance evidence. Це **підготовлені, не надіслані** пропозиції, не 50 виграних угод.

## Обов'язкові негативні перевірки

- Відсутній/не затверджений/відкликаний Plan або Action; self-approval; policy/plan version drift; дубль Action/друга спроба.
- 49 із 50 завершених Run, один частковий/FAILED/AMBIGUOUS або незафіксований native document relation.
- Повторні Candidate/Account/external source hash, Sales Lead ID або Document ID.
- Інший tenant; підмінений `source_federation_run`, `native_discovery_run`, membership `account_id` або `source_hash`.
- Старий native Growth run випав із bounded `latestRuns(20)` або membership із `membershipsForUniverse(200)`: **fail closed**, вимагати архівний незмінний snapshot / доказ, а не домислювати успіх.
- Дочірній Run `completed` без валідного незалежного native Growth→Sales→Documents доказу; спроба трактувати підготовлені drafts як `sales.won_deals`.

**Критерій приймання:** усі чотири GitHub checks **саме останнього SHA** (CI, Runtime, Frontend, Documentation) green **плюс** реальні tenant-native 50/50/50 докази та збережена незалежна evaluation. Не робити merge/production rollout за одним тільки unit green.

## Межа автоматизованого тесту

Операторські маршрути усувають необхідність вручну створювати початковий Discovery Plan. Вони НЕ створюють 50 реальних кандидатів, не замінюють Growth signal/research/scoring, champion-контакти, незалежні погодження та native Action worker. Без фактичного тестового tenant з 50 справжніми Growth Candidate та 50 незалежно підтвердженими native Lead/Document receipts Package A не вважається прийнятим незалежно від зеленого CI.

## Перевірка доказів канонічного виконання (2026-10-15)

Golden 50 fan-out preview і Goal outcome aggregation тепер читають **новий** `cos_workflow_runs/steps` через read-only authority adapter, а не вимагають legacy `cos_federation_runs/steps`. Усі completed canonical Actions повторно перевіряються за pinned Plan, independent human Approval, Policy, єдиною успішною attempt, native action identity і tenant. Історичні rows зберігають read-only adapter; колізія двох execution authorities завершується помилкою, не обирає «кращу».

Зміни в коді **не сертифікують** live Golden 50. До release gate потрібні 50 фактичних native Growth Candidates/Accounts, 50 human-approved child Plans та їх 200 окремо погоджених Actions, 50 persisted Sales Leads, 50 persisted Documents relations, незалежний `native_goal_aggregate_v1` evaluation, staging parity усіх трьох контурів, migration/recovery негативні випробування та operator sign-off на єдиному фінальному SHA. Без цих доказів merge заборонено.

### Післяміграційний read-only доступ

Після контрольованого перенесення pristine історичного Run його незмінний запис залишається у `cos_federation_runs/steps`, а новий canonical Run має той самий Run ID. Це **не** подвійне виконання: read model визнає canonical лише за наявності підтвердженого `promoted/match` migration ledger, незмінного source digest, перевіреного post-cutover integrity, точного зв'язку Plan/Run, ідентичних signed permit hashes і окремого `cutover_accepted` operator audit event. Непідтверджене перекриття, змінені frozen записи, чужий Plan або невідповідний audit event блокуються. Жодних Action replay чи dual-write у read model немає.
