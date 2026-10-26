---
title: "COS Golden 50: Capability Operability Standard"
description: "Нормативний стандарт перевірки повного доступного функціоналу COS, контрольованого виконання й незалежного бізнес-результату."
status: draft
kind: architecture
updated: 2026-10-26
---

# COS-G50-CAPABILITY-OPERABILITY-1.0

**Статус:** нормативне ТЗ і план acceptance, НЕ production-сертифікат. **Авторитет виконання:** лише чинні Domain Command/Query buses, Kernel Action/Policy/Approval та canonical Platform Workflow. **Заборонено:** другий executor, обхід людських погоджень, synthetic business outcomes, неконтрольований retry.

## 0. Розмежування двох програм

1. **Golden 50 Business E2E** (існуючий reference flow): рівно 50 унікальних native Growth Candidates, 50 незалежно погоджених дочірніх Plans по 4 Action, 50 Sales Leads, 50 template-backed Documents, перевірені native зв'язки та окрема збережена оцінка Goal. Не плутати з 50 виграними угодами.
2. **Golden 50 Capability Operability** (цей стандарт): незалежна 50-пунктова матриця перевірок **для кожної застосовної операції COS** у визначеному release/tenant/initiator cohort. Число 50 тут є кількістю типів контрольних перевірок, а НЕ вимогою знайти рівно 50 команд і НЕ доказом, що всі Domains повністю реалізовані.

Файл виконуваної специфікації: [capability-operability-matrix.v1.json](../../resources/testing/golden50/capability-operability-matrix.v1.json). 10 категорій × 5 перевірок; кожний запис містить умову, очікуваний результат і тип доказу. Результат за замовчуванням NOT RUN.

## 1. Єдиний universe можливостей

**Єдине визначення операцій**: Domain module.php → contributions.capabilities та contributions.capability_contracts → CanonicalCapabilityCatalog. Описові capabilities НЕ означають можливості виконання. CanonicalCapabilityGateway і FederationCapabilityBindingResolver дають різні tenant/runtime projections, а не новий source of truth.

Для кожного запису формується immutable inventory snapshot:
- canonical ID, owner Domain, version, digest input/output schemas, execution binding, lifecycle, side effect level;
- категорія: typed contract / descriptive-only / internal-only / deprecated / unknown executable binding;
- зареєстрований живий handler/adapter в **конкретному процесі**, active module/permission у **конкретному tenant**;
- допустимі initiators (human UI, human API, AI, CRON, Event, Workflow), фактичні маршрути й політика Approval;
- схема native бізнес-результату, owner readback і набір обов'язкових тестів.

**Важливо:** declared ≠ validated schema ≠ bound handler ≠ tenant-authorized ≠ successfully executed ≠ verified native outcome.

### Зафіксований baseline огляду main

Огляд за main SHA a4498c4530ad1fcf24051cd32483146b4dd4f555: Property 5, Growth 4, Sales 2, Documents 3, Capital Markets 1, Federation 1 = **16 typed contracts**. Внутрішній federation.plan.approval НЕ є планиною бізнес-командою. Ці числа є **джерелом для початкового gap audit, не показником runtime availability**. Реальний cohort перераховується на кожному фінальному SHA.

Property має наявні Asset/Inventory/Listing/Publication/Intake/Identity/Analytics сценарії, але частина Domain services не має typed contracts. Такі операції ставляться в **UNCONTRACTED / NOT EXPOSED**, а не автоматично в PASS. Не створювати дублікати Domain services лише для наповнення каталогу.

## 2. Формальний статус працездатності

| Рівень | Назва | Що доведено |
| --- | --- | --- |
| D0 | DECLARED | Є опис у Domain manifest |
| D1 | CONTRACT_VALID | Input/output schema, safety metadata й ownership проходять перевірки |
| D2 | BOUND | Живий adapter/Action handler у запущеному процесі, owner підтверджений |
| D3 | ADMITTED | Actor, tenant, permissions, module activation, policy й approval валідні |
| D4 | EXECUTED | Канонічний Command/Query або Workflow→Action виконав одну операцію з durable receipt |
| D5 | OUTPUT_VERIFIED | Результат відповідає pinned output schema і доказу спроби |
| D6 | BUSINESS_VERIFIED | Незалежний Domain readback, native ID/relationship і потрібний критерій Goal підтверджені |
| D7 | CERTIFIED | Негативні, concurrent, recovery, event та operator gates пройдені на exact SHA/cohort |

Статус D7 **не глобальний**: він прив'язаний до capability ID+version+digest, tenant, release SHA, конкретного runtime fleet, ініціатора та бізнес-сценарію. Для read-only Query D4/D6 мають окремі read-only evidence правила. Наявність D2 не надає повноважень D3. Технічний Run completed не дорівнює D6.

## 3. Контракт виклику та обов'язкові receipts

Логічний immutable request envelope (контракт, не обов'язково нова фізична таблиця):
- request_id, correlation_id, causation_id, idempotency_key;
- server-authenticated initiator_type, initiator_id, tenant_id, role/scopes;
- capability_id, version, contract_digest, input_digest, input_payload;
- requested_at, policy_decision_id, Plan/Step provenance за потреби;
- mode: query / direct_human / governed_action / intent_only;
- deadline, retry_class, effect_class.

Диспетчеризація:
- query: тільки owner QueryBus/read adapter;
- internal human command: тільки owner CommandBus після guard;
- side-effectful approved action: pinned Goal Plan → canonical Platform Workflow → Kernel Action/Policy/Approval → Domain-owned Action handler;
- AI/CRON/Event: спочатку авторизований **intent** або read; не отримують людські сесії й не обходять Action approval. У поточному baseline machine-intent обмежений Property Bulk Intake.

Сервер має писати durable execution receipt з request/Plan/Run/Step/Action/attempt IDs, immutable digest, status, error class, output validation і native references. Незалежний evaluator пише ОКРЕМИЙ business outcome/evidence зі збереженою політикою оцінювання. Некоректний output після успішної зовнішньої мутації → AMBIGUOUS/RECONCILE, НЕ автоматичний повтор.

**Events:** Domain event із version, tenant, producer, causal ID, native aggregate ref; transactional outbox там, де застосовно; consumer registration з allowlist; event → intent → той самий Policy/Approval. Event delivery ≠ permission, repeat event не створює новий ефект. Якщо standard event source/consumer не реалізований, case BLOCKED (або обґрунтоване N/A для capability без подій), а не PASSED.

## 4. Універсальна матриця 50 перевірок

Реєстр у JSON, ідентифікатори CO-01…CO-50:
- CO-01…05 **Catalog**: інвентар, ownership, visibility, uncontracted functions, cohort freeze.
- CO-06…10 **Contract**: input, output, digest/version, risk policy, lifecycle.
- CO-11…15 **Binding**: real handler, canonical dispatch, module, fleet parity, readiness boundary.
- CO-16…20 **Initiator**: human UI/API, AI, CRON, Event origin.
- CO-21…25 **Security**: least privilege, tenant isolation, spoofing, Approval, self-approval.
- CO-26…30 **Workflow**: side-effect-free preflight, immutable Plan, single writer, receipt, outcome checkpoint.
- CO-31…35 **Reliability**: replay same key, conflicting key, concurrency, crash, ambiguity.
- CO-36…40 **Outcome**: native readback, relations, full evidence, revoked evidence, independent Goal evaluation.
- CO-41…45 **Events**: outbox, provenance, consumer policy, dedupe, failed delivery/loop.
- CO-46…50 **Release**: staging isolation, deployment parity, observability, rollback, operator sign-off.

**Правило застосовності:** на кожну scoped capability та ініціатора утворюється набір необхідних cases; якщо Query не має Workflow mutation або Event subscription, непридатний case допускає тільки NOT_APPLICABLE_WITH_EVIDENCE з формальною причиною. N/A **не припустиме** для неготового/незареєстрованого handler, відсутнього контракту, проваленого tenant gate або пропущеного потрібного Goal Outcome. Дескриптивні capabilities входять у gap register, але не в знаменник executed capability coverage.

## 5. Підрахунок без косметики

Для кожного cohort:
- inventory completeness = enumerated manifest definitions / all observed manifest definitions; ціль 100%;
- contract coverage = typed business operations / audited executable business operations (і окремо перелік uncontracted);
- binding coverage = verified runtime bindings / scoped typed contracts;
- test coverage = PASS applicable cases / all applicable cases; FAILED і BLOCKED залишаються в знаменнику;
- business verification coverage = independent native verified outcomes / all expected executed outcomes;
- scenario Golden 50 = **50 distinct candidates, 50 distinct Sales Lead IDs, 50 distinct Document IDs, 50 complete 4-Action chains, one separate saved Goal evaluation**.

У протоколі ОБОВ'ЯЗКОВІ цілі чисельники і знаменники, список виключень, причина кожного BLOCKED. Не змішувати smoke/unit success із operator-certified readiness. Сертифікат не може бути PASS при P0 FAIL/BLOCKED, невідомому denominator або відсутній independent sign-off.

## 6. Release gates

| Gate | Умова переходу | Доказ |
| --- | --- | --- |
| G0 | Ізольоване оточення, immutable SHA, tenant, два незалежні людські облікові записи, дозволений data set | Environment attestation |
| G1 | Повна динамічна інвентаризація, усі descriptive gaps показані, orphan bindings відхилені | Census + drift |
| G2 | Schema/policy/version/digest валідні, Contract/Output snapshots pinned | Contract report |
| G3 | Handler у потрібних API/worker/cron процесах підтверджений; вимкнені модулі не виконуються | Runtime fleet witnesses |
| G4 | Identity/tenant/API/UI/AI/CRON/Event authorization й negative admission пройдені | Policy decisions |
| G5 | Єдиний Workflow writer, Action approval, idempotency, crash/reconcile під навантаженням | Native execution receipts |
| G6 | Native readback + linked outcomes + Event/consumer правила підтверджені | Domain evidence graph |
| G7 | Реальний Golden 50 Business E2E з 50/50/50, 200 Action approvals, без повторів, незалежною Goal evaluation | Per-candidate ledger |
| G8 | На тому самому фінальному SHA CI+Runtime+Frontend+Documentation green, independent operator sign-off, rollback готовий | Signed release certificate |

**Інтеграція поточної черги:** PR #414 задає архітектурний контракт, PR #415 додає static census/definition snapshots/output validation, PR #416 розширює runtime witness, pinned Action output і Plan preflight. Це залежні інкременти, не автоматично прийнятий production. Матриця CO не повинна дублювати ці компоненти, а має викликати їх після merge й збирати докази.

## 7. Окремі обов'язкові Domain сценарії

**Property, еталон підключення:** typed Asset create/get/search/update; governance bulk.create із 10 реальними tenant-native Assets; незалежне підтвердження дублікатів. Далі стандартизувати Inventory create/get/price/status/reserve/release та Listing create/update/publish/hide поверх чинного Property runtime; поки вони без type contracts, тестова матриця фіксує GAP і НЕ виконує їх через неавторизований shortcut.

**Growth→Sales→Documents Golden 50:** реалізувати існуючий [операторський E2E runbook](../02-workflows/federation-golden-50-operator-acceptance.md) без synthetic IDs. One source Discovery Approval, 50 child Plans, 4 Actions each (qualify → prepare → target.sales → proposal.prepare), незалежний Plan Approval та кожної Action, 50 зв'язків Sales/Document. Допускається staging fixture для unit/integration перевірки pipeline, але він НЕ сертифікує live Golden 50.

**Sales:** sales.create_task з native Deal та receipt; sales.deal.won.observation read-only і не може бути підмінений prepared proposal.

**Documents:** proposal.prepare не надсилає та не підписує; signature.request/sign потребують реального перевірного provider evidence. На test environment використовувати дозволений sandbox провайдера; без provider proof не називати Sign бізнес-підтвердженим.

**Capital Markets:** validated research result відрізняти від прибутку чи дозволу на live trading. Загальне execution certificate не означає фінансову безпечність будь-якого сценарію.

**Federation:** plan.approval є platform governance action, ніколи user-plannable business capability.

## 8. Тестові дані та захист

- staging з MySQL, чергою, worker, outbox/event adapter; tenant A та B; actor manager/approver окремі; read-only user; service principal без людської сесії;
- real native tenant records для інтеграційної перевірки; canary datasets для безпечного contract smoke; окремо live business dataset для G7;
- зовнішні листи, підписи, фінансові платежі, публікації, trading тільки sandbox/mock або обов'язковий operator stop; **жодного реального незворотного ефекту під виглядом тесту**;
- fault-injection після Domain commit, до запису receipt, перед/після queue ack, під час Event consumer, на різних workers; усі невизначеності fail closed.

## 9. Формат звіту

Звіт заповнюється **тільки після запуску тестів**:
- standard ID, matrix digest, git SHA, timestamp, environment, tenant hash, module/contract snapshots, fleet hashes;
- test case ID, capability ID+version+digest, initiator, preconditions, applicability, run/step/action/attempt references, status PASS|FAIL|BLOCKED|NOT_APPLICABLE_WITH_EVIDENCE;
- input/output receipts із redacted payload hashes, approval/policy proofs, native readback proofs, event correlation й trace references;
- discrepancy, owner, severity P0/P1/P2, remediation PR, retest SHA;
- independent business evaluator і separate human operator sign-off.

**Заборонено** ставити PASS із факту наявності файла, metadata contract, proposed Plan, queued Job, worker ack, HTTP 200 без перевіреного результату або fixture-based synthetic aggregate.

## 10. Практичний порядок впровадження

**P0.1**: після погодженого merge capability changes запустити existing census із PR #415, отримати фактичний denominator і contract/binding gap register. **P0.2**: доповнити Property Inventory/Listing як типізовані owner contracts і native outcome reads; не будувати ще один Runtime. **P0.3**: реалізувати runner матриці з reusable fixtures, protected invocation + negative paths, збереженням evidence у QA storage (не в SQL Domain tables напряму). **P0.4**: інтеграційні MySQL, canonical Workflow + Kernel Action/Policy/Approval, fault injection, Event consumer перевірки; усе на exact SHA. **P0.5**: живий staging Golden 50 з двома реальними операторами й 50/50/50; G8 release board.

### Межа цього PR

Це **нормативна специфікація + машинозчитувана матриця + статичний contract guard**. Тут **немає** production тесту, автоматичного запуску Action, нового event executor, дозволу AI/CRON на мутацію чи фактичних 50/50/50 доказів. Статичний guard перевіряє цілісність test catalog та metadata на поточній гілці, а не видає runtime PASS.
