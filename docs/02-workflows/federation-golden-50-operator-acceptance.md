---
title: "Операторське приймання сценарію Golden 50"
description: "Послідовність перевірки 50 унікальних кандидатів Growth, незалежних погоджень, лідів Sales, документів та оцінки бізнес-цілі."
status: draft
updated: 2026-10-14
---

# Package A: операторське приймання Golden 50 (лише реальні дані)

**Статус:** інструкція / release gate, а не сертифікат. Проходження unit-тестів або MySQL smoke з відкачаними fixtures **не** дорівнює 50 реальним Sales Leads і 50 Documents.

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
2. Створити один approved Discovery Plan для цього Goal через авторизований Federation Plan application boundary і реальний `growth_market_universe`, отримати незалежне людське погодження Plan і `growth.market.discovery`, виконати та фіналізувати source Run. Наразі окремої загальнодоступної кнопки для створення *parent* Discovery Plan немає: цю операцію треба здійснювати через контрольований інтеграційний/операторський процес, не прямими фіктивними SQL-вставками.
3. У `GET /api/v1/federation/fanout/preview` передати `goal_id`, `source_run_id`, `source_step_id`, `requested=50` або послідовно `10` і `40`, `policy_id`, `policy_revision`, `template_id`, `expected_value`, `recommended_play`, `recommended_action`. Має бути `ready=true`, `eligible>=requested`, жодного дубля source/Account/Candidate. GET не має побічних ефектів.
4. Тими ж полями зробити `POST /api/v1/federation/fanout/propose` із сесійним CSRF. Результат: **50 proposed immutable Plans**. Повторення з тими самими ідентичностями не створює додаткових планів.
5. **Окремо для кожного Plan**: canonical незалежний Plan approval; `POST /api/v1/federation/runs/start` із `plan_id` і `approval_action_id`, CSRF. Кожен `POST /api/v1/federation/runs/{runId}/advance` просуває **лише один** перехід. Action переходить у стан очікування людського погодження, а не виконується автоматично. Після worker execution перевіряти receipt і продовжувати до `completed`; негативний або неоднозначний результат відправляти на ручне `/recovery` та `/reconcile`, не автоматично повторювати side-effect.
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
