---
title: Єдина Capability Library — повнота визначень, CI та міграція Domains
description: Стандарт контрактів і контроль повноти декларацій усіх Domains без другого executor.
status: implementation-draft
updated: 2026-10-29
kind: architecture
---

# COS Capability Library: повнота та стандартизація

## Мета

Canonical Capability Library є **єдиним джерелом контрактів** для викликів із UI, API, AI, CRON, Events та Goal Planner. Domain `module.php` є першоджерелом ідентичності, ownership, version, input/output schemas, side-effect, policy та execution binding. `CanonicalCapabilityCatalog` є спільною read-only projection. Реальне виконання залишається у власних Domain Query/Command handlers, Kernel Actions і Platform Workflow.

**Необхідно розділяти три факти:** існування декларації, реально зареєстрований handler і дозвіл конкретного tenant. Вони ніколи не означають автоматичного `Goal ACHIEVED`.

## Поточний реалізований інкремент

### 1. Повна інвентаризація Domain definitions

`Kernel\Module\CapabilityCatalogCoverageAudit` отримує **всі** `CanonicalCapabilityCatalog::declared()`, а не обрану підмножину Golden 50. Для кожного ID створюється детермінований запис:

- `id`, `owner_domain`, `definition_status`;
- `typed`, `kind`, `version`, `lifecycle`, `side_effect_level`, `approval_policy`;
- `contract_digest`, `input_schema_digest`, `output_schema_digest` для валідного контракту;
- `binding_kind`, `dependencies`, `documentation`, `tests`;
- впорядковані `issues` з машинними кодами й severity;
- явні `runtime_binding_verified=false`, `worker_fleet_certified=false`, `tenant_authorized=null`, `business_evidence_verified=false`.

Звіт не змінює налаштування Domains та **не створює новий реєстр у БД**.

### Класифікація legacy-декларацій

У контрактах Domain з'явилося поле `contributions.capability_profiles`: мапа точного legacy ID на один із двох статусів. `feature_surface` описує можливість, сторінку, доступ або технічну складову, яка **не** має власного стандартного handler і не може бути універсально викликана. `contract_candidate` описує потенційну самостійну бізнес-операцію, для якої потрібні повний `CapabilityContract`, local schemas, native adapter/action owner, permission, idempotency, тести та evidence. Обидва типи залишаються **невиконуваними** в Canonical Gateway до фактичної інтеграції.

Аудит охоплює **всі 13 Domain manifests**. Наразі 190 ідентичностей: **16 typed contracts, 75 інформаційних feature surfaces і 99 contract candidates**. Декларації HR, Finance, Procurement і Construction поки не додають Capability IDs. Це **класифікація декларацій**, а не 99 доведених відсутніх реалізацій: у Domain може існувати звичайний handler без canonical contract. Кожен Domain owner має підтвердити семантику кандидатів перед міграцією.

Метадані декларуються лише у вихідному `module.php`. `ModuleContributions` відхиляє ID, якого немає в цьому Domain; невідомі статуси й спроби reclassify typed executable як legacy. Невідомі надалі IDs будуть видні як `UNCLASSIFIED_LEGACY_CAPABILITY`.

CLI: `--require-classified` є CI-gate на повну класифікацію і відсутність структурних помилок. `--require-executables-ready` залишається суворим майбутнім release gate та завершується помилкою, доки є кандидати без контрактів. `--require-all-typed` залишається надсуворою сумісною перевіркою, непридатною для інформаційних маркерів.

### 2. Стандарт станів

| Code | Значення | Дозвіл виконання |
| --- | --- | --- |
| `DECLARATIVE_ONLY` | ID є в manifest, але немає typed contract чи явної semantic classification | Немає |
| `FEATURE_SURFACE_NON_EXECUTABLE` | Інформаційна/навігаційна можливість, не самостійна canonical команда | Немає |
| `CONTRACT_CANDIDATE_BLOCKED` | Операція-кандидат, для якої немає повного typed contract | Немає |
| `SCHEMA_VALID_BINDING_UNVERIFIED` | Typed contract і локальні схеми пройшли перевірку | Не встановлений цим звітом |
| `CONTRACT_INVALID` | Invalid schema, dependency чи дубль виконуваного binding | Немає |

Інші осі визначаються незалежно: `binding_witness` від поточного request container, tenant `status` із Gateway, approval decision і native business evidence від відповідного Domain.

### 3. Машинні проблеми

- `MISSING_TYPED_CONTRACT`: legacy декларація, яку необхідно мігрувати до typed contract або явно залишити інформаційною;
- `INVALID_SCHEMA`: невідповідність локальному підтримуваному JSON Schema профілю;
- `DUPLICATE_EXECUTION_BINDING`: дві typed Capabilities претендують на однаковий handler; обидві позначаються invalid;
- `UNKNOWN_OR_SELF_DEPENDENCY`: залежність посилається на невідому або саму себе;
- `CYCLIC_CAPABILITY_DEPENDENCY`: у dependency graph є цикл, отже немає однозначного порядку виконання;
- `BINDING_KIND_MISMATCH`: тип Query/Command/Job/Workflow/Agent Tool не відповідає declared binding family;
- `DEPENDENCY_NOT_TYPED`: залежна Capability існує лише як декларація;
- `DOCUMENTATION_OR_TESTS_NOT_DECLARED`: відсутнє джерело опису або автотест;
- `MISSING_OR_UNTRUSTED_PROOF_FILE`: тест/документація відсутні чи не належать до дозволеного локального джерела.

Заяви про успішність tests[] **не робляться** за наявністю файлів. Виконання тестів фіксується окремо в CI.

### 4. Єдиний CLI / API / UI

- CLI: `php bin/cos-capability-census.php` повертає версійований JSON.
- CI структурна перевірка: `php bin/cos-capability-census.php --strict`, exit 1 за invalid typed contracts.
- CI семантична перевірка: `php bin/cos-capability-census.php --strict --require-classified` для кожного Domain manifest.
- CI перевірка повної міграції: `--require-all-typed`, exit 2, якщо лишилися declarative-only; **не активувати як release gate, поки legacy не мігрований**.
- API: `GET /api/v1/capabilities/coverage` для tenant manager; внутрішні Federation approval capabilities не включаються в перелік ID, але загальні лічильники охоплюють всі визначення.
- API: `GET /api/v1/capabilities/bindings` дає інший, tenant-aware current-container status. Ніяких записів і нових дозволів.
- API: `GET /api/v1/capabilities/{id}/versions/{version}` перевіряє точну **поточну** версію та повертає fingerprint і contract JSON; стара недоступна версія не перенаправляється на нову. Цей endpoint не є сховищем історичних snapshots.
- API: `GET /api/v1/capabilities/{id}/readiness` повертає три різні ознаки: definition readiness, registration witness поточного runtime та tenant execution mode. Він **не** надає Approval чи права на execute.
- UI: `/workspace/capabilities` показує статистику, фільтри, статуси, перелік проблем, handler witness, контрактні тести/документацію, посилання на JSON. Збережено реальний Property Asset quick-create через існуючий Gateway.
- UI: `GET /workspace/capabilities/{id}` дає контракт конкретної функції, а `POST /workspace/capabilities/{id}/validate` із CSRF перевіряє JSON-вхід через **той самий** `CanonicalCapabilityGateway::validateInput`. Під час перевірки не створюються Action, Plan або Run. Декларативним функціям без typed contract форма недоступна.
- Архітектурний тест `tests/architecture/cos_capability_catalog_experience.php` перевіряє read-only UI/API boundary та заборону нового dispatch із цієї форми.
- CI: `cos-capability-definition-coverage` зберігає артефакт JSON зі snapshot для code review; не публікує tenant secrets.

### 5. Обмеження виконання

Картка «SCHEMA_VALID» **не дає права** direct invoke. Зміни виконуються лише через Gateway і його чинний tenant/permission/policy контроль або погоджений Platform Workflow. AI/CRON/Event можуть лише запропонувати Plan чи працювати через раніше затверджену окрему делегацію. Production Worker залишається fail-closed без acceptance.

## Повна міграція до єдиного типізованого стандарту

Для кожного Domain виконується однаковий процес:

1. Експортувати `module.php` та всі нинішні `capabilities`; класифікувати як `business executable`, `query`, `internal technical` або `descriptive`.
2. Для виконуваної команди створити `CapabilityContract` з унікальним `id@version`, owner, строгою input/output schema, binding, permission, approval, retry, timeout, idempotency, native evidence і tests.
3. Перевірити, що binding існує в реєстрах **наявних** Query/Command/Action handlers і належить правильному Domain.
4. Додати індивідуальний integration test для native input, output, tenant, duplicate key, approval, rollback/unknown.
5. Ввести UI/API/Goal-Plan discovery лише після структурної перевірки та окремого runtime admission. Службові internal operations залишаються прихованими.
6. Для `descriptive` capabilities визначити явне правило: informational / non-executable, а не автоматично приписувати фальшивий Command contract.
7. Після вичерпання законно інформаційних legacy описів погодити окрему політику щодо `--require-all-typed` або чіткий `descriptive` scope для цих декларацій. Не змінювати значення існуючих ID приховано.

## Перевірки та приймання

- `tests/unit/cos_capability_full_domain_coverage.php`: звіт відтворюваний, всі declarations враховані, статичний аудит не прикидається сертифікацією, typed fingerprint детермінований, legacy ID не виконується, два owner з однаковим binding позначаються помилкою обидва.
- CI fast: compiler/census + Contract/Goal/Golden50 regressions.
- Runtime MySQL: перевірка approval/receipt native execution через чинну Pipeline, але **не** автоматично звіт про повну fleet certification.

**Не завершено:** міграція кожної legacy descriptive declaration, immutable historical multi-version registry, fleet-level worker/deploy attestation, Agent/Tool/CRON event subscription contract governance, незалежний native Goal evaluation для всіх Domains та фактичний Golden 50. Вони залишаються окремими acceptance gates, а не декорацією у «100% готово».
