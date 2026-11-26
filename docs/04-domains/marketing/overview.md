---
title: Маркетинг як окремий домен COS
description: Планування маркетингових кампаній і безпечне читання Meta Ads без рекламних мутацій.
status: draft
updated: 2026-11-24
kind: domain
version: 0.2.3
---

# Огляд домену Marketing

Marketing — окремий bounded context, що володіє рекламними планами, внутрішніми кампаніями, їхніми версіями, аудиторіями, креативами та спостереженнями рекламних провайдерів.

**Стан:** D2, реалізовано M0–M3, основний M4 та дорадчі компоненти M5. Незалежне приймання на staging, реальні джерела і фінансові результати не підтверджено. Домен вимкнений за замовчуванням.

## Розподіл відповідальності

- **Marketing**: маркетингові кампанії й чернетки, рекламні аудиторії, креативи, імпорт Meta Campaign/AdSet/Ad та спостережені Insights.
- **Growth**: Market Universe, Signals, OpportunityCandidate, Qualification, Outreach, Response Routing і Growth Learning. Жодного перенесення Growth даних не відбулося.
- **Product**: товари, варіації, доступність і ціни. Marketing зберігає лише посилання та не оголошує їх перевіреними до приймання Product API.
- **Sales**: первинні факти лідів, угод і продажів.
- **Finance**: грошові ліміти, резервування, справжні витрати, платежі та фінансова звірка. Показник `spend` із Meta — не бухгалтерський Expense.

Внутрішня Marketing Campaign може мати декілька зовнішніх PaidMediaCampaign. Імпортовані рекламні об'єкти залишаються `EXTERNALLY_MANAGED`.

## Фактично додані можливості

48 типізованих capabilities у поточному manifest, зокрема: `marketing.campaign.search`, `marketing.campaign.get`, `marketing.campaign.create`, `marketing.paid.account.list`, `marketing.paid.insights.query`, `marketing.paid.sync.history`. Для них діють Canonical Capability Gateway і перевірка tenant менеджера. Нових універсальних executor або CommandBus не створено.

На UI передбачено Overview, Campaigns, Campaign Workspace, Paid Media Accounts, Performance та Action Queue. Менеджер може створити внутрішню чернетку, під'єднати read-only Meta Ad Account через opaque Vault reference, імпортувати і переглядати provider observations.

Створення в Meta у стані PAUSED, активація та пауза мають canonical Workflow Action bindings, але потребують незалежного погодження, перевіреного профілю провайдера, Finance та readback. Довільні платні зміни й автоматичне коригування бюджету залишаються заблокованими.

## M2a: Креативи та незалежний перегляд рекомендацій

Користувач у `/marketing/campaigns/{id}` створює Creative Draft, додає HTTPS destination, текст, CTA, локаль та необов'язковий Product reference або SHA-256 медіа. Модель зберігає `product_verification=PENDING_PRODUCT_ACCEPTANCE`, `destination_verification=UNVERIFIED` і `compliance_state=NOT_REVIEWED`. Вона **не підтверджує** доступність товару, безпечність URL чи політику Meta.

Усі правки Creative зберігаються як послідовність `marketing_creative_revisions` із content hash, actor і revision fencing. При зміні ревізії подані раніше, але ще не розглянуті рекомендації стають застарілими та не можуть бути прийняті.

Поради аналітика зберігаються в `marketing_analyst_proposals` із reference на конкретну Campaign і Creative revision, автором і явно зазначеними джерелами evidence. У M2a origin встановлює **сервер** як `HUMAN`, а evidence — `UNVERIFIED`: користувач не може видати людську нотатку за Hermes або verified provider insight. Інший менеджер може `ACCEPT` / `REJECT` із причиною; автор не може погодити власну пропозицію. Прийняття рекомендації **не змінює** статус Marketing Campaign і не запускає Meta/Finance операції.

Шість capabilities: `marketing.creative.create`, `marketing.creative.revise`, `marketing.creative.list`, `marketing.analyst.proposal.submit`, `marketing.analyst.proposal.list`, `marketing.analyst.proposal.review`. Вони працюють через той самий Canonical Capability Gateway; мутації `internal` і `human_only`. Відсутні token integration, Hermes autonomous invocation та паралельний executor.

Схема: `app/migrations/20261119_000154_marketing_planning_m2.sql`; інтеграційна перевірка: `tests/integration/marketing_m2_planning_mysql.php`. Модуль вимкнений за замовчуванням.

## M2b: Product Management як джерело істини для Creative Draft

`marketing.product.draft_check.get` є tenant-scoped **read-only Query** через Canonical Capability Gateway. Marketing має власний read port, а Symfony anti-corruption adapter викликає **лише** `ProductManagementService::getProduct()`, `PricingService::get()` та `ActiveModuleResolver::isEnabled()`. Прямого SQL у таблиці Product немає. Якщо Product module вимкнено для tenant, перевірка fail-closed.

У Campaign Workspace доступна форма ручної перевірки `product_id` (32-hex), опційного `variant_id` та валюти. Результат читає актуальні Product/Variant status, revision і базову ціну. Власний Product статус `draft` може бути використаний як посилання **для чернетки**, але не означає готовності до реклами. Чужий tenant, інший Variant, відсутній товар, архівний товар або вимкнений Product module не проходять перевірку. Усі значення з джерела — лише поточний readback; Product змінюється незалежно від Marketing.

Відповідь завжди має `launch_eligible=false`, `inventory_verified=false`, `external_destination_verified=false` та `provider_ad_policy_verified=false`. До платного запуску все ще потрібні перевірка складу для конкретної локації, HTTPS destination, рекламна політика, Finance envelope/Approval та canonical Workflow. Це не створює Finance expense або Meta campaign.

**Докази D2:** `tests/unit/marketing_product_draft_check.php` та `tests/integration/marketing_product_draft_mysql.php` (реальна Product Management persistence/price, tenant/module isolation). D3/D4/D5 не заявлено.

## M2c.1: TargetSpec для чернетки креативу

Опційний provider-neutral `target_spec` у Creative create/revise містить 1–20 дволітерних market country codes та текстову гіпотезу щодо аудиторії (5–500 символів). Domain нормалізує порядок кодів, відхиляє дублікати, невідомі поля та спроби передати provider audience ID або право запуску. Формат коду перевіряється синтаксично, а не за реєстром країн. Server зберігає `verification_status=UNVERIFIED` і `provider_eligible=false`. Відсутність TargetSpec не змінює legacy payload/idempotency hash.

Workspace дозволяє створювати, переглядати, змінювати або прибирати цю гіпотезу у новій Creative revision. Existing JSON persistence, tenant scope, receipt і stale-review fence збережені; окремої таблиці або executor не створено. Це не є завершеною моделлю Audience/AdSet, перевіркою policy, автоматичною класифікацією аудиторії чи Meta API mutation. Докази: unit schema/policy та MySQL revision/readback тести; незалежне приймання попереду.

## M2c.2: Вибір товарів (Product Selector)

`marketing.product.selector.search` — read-only tenant-manager Query через наявний Product read port. Пошук за назвою/SKU через Product owner, сторінки до 20 variant rows, повторне читання поточного статусу Product/Variant; архівні або неактивні варіації відсіюються. Pagination працює за source rows, тому відфільтрована сторінка може бути порожньою. Workspace дозволяє обрати SKU, виконати live price check та підставити Product reference у новий Creative Draft. Початковий M2c.2 використовував Variant selection для readback; M2c.3 додає версіоноване посилання. Немає stock guarantee, paid eligibility або прямого SQL. Unit та native MySQL tests додають tenant/module/role fences. D3–D5 не заявлено.

## M2c.3: Variant/Location references та stock readback

Creative create/revise приймають опційні canonical `variant_ref` та `location_ref` у версіонованому JSON. Це посилання, а не підтверджені факти: наявна ознака Product verification не змінюється. Старі payload без цих полів не отримують нових ключів. Workspace підставляє вибрані Product/Variant/Location та відображає збережені посилання.

Existing `marketing.product.draft_check.get` підтримує опційний `location_id` та `destination_url`. Через Product owner `balance()` читається on-hand/reserved/available/revision для активної варіації конкретного tenant і location. Non-stock variant позначається NOT_TRACKED; відсутній/неактивний Product не дає stock evidence. Decimal залишається string, без float conversion. Це поточне спостереження, а не резерв або гарантія доступності. Stock location наразі вводиться за ID; довідник локацій потребує Product-owned list contract.

Destination URL проходить синтаксичну HTTPS перевірку без DNS/HTTP, credentials, IP та нестандартних портів. Reachability, redirect safety, ownership і відповідність товару залишаються непідтвердженими. Немає SSRF-prone server fetch. `launch_eligible`, `inventory_verified`, `external_destination_verified` залишаються false; Meta/Finance writes заборонені. Unit та native MySQL tests додано; CI і незалежне приймання потрібні.

## M2c.4a: Чернетки аудиторій і груп оголошень

Marketing має tenant-scoped Audience та внутрішній AdSet Draft, відокремлений від імпортованих Meta AdSet. `marketing.audience.save/list` та `marketing.adset.save/list` — типізовані контракти. Save створює запис або додає ревізію за ID + expected_revision. AdSet фіксує Campaign revision, Audience revision і 1–20 Creative revision pins; застарілі або чужі посилання блокують збереження, readback показує pins_stale. Ідемпотентне повторення повертає початкову незмінну ревізію. Плейсменти, optimization і planned_daily_budget є лише гіпотезою. Finance не резервував бюджет, Meta нічого не отримує, launch_eligible=false. Finance reservation залишається наступним кроком.

## M2c.4b: Робочий простір аудиторій і груп оголошень

Campaign Workspace має форми create/revise для спільних tenant Audience та campaign-bound AdSet Draft. UI вибирає поточні Audience/Creative revision pins, передає expected_revision і campaign_revision, показує попередні pinned revisions та stale warning. Застарілі pins не підміняються автоматично новими. CSRF, tenant manager, module activation та native Domain validation залишаються обов'язковими; HTML mapper не приймає tenant/approval/provider authority. Budget — лише planning amount, не Finance reservation. Unit tests перевіряють form mapping, malformed pins і wiring; браузерне та staging-приймання ще потрібні.

## M2c.5a: Контекст зовнішнього аналітика та контракт рекомендації

`marketing.analyst.context.get` — локальний read-only Query, що збирає лише обрані tenant Campaign/Creative та опційно актуальні AdSet/Audience revisions. При AdSet pin перевіряються stale state і належність Creative. Product observations читаються через існуючий Product port, якщо є валюта AdSet; відсутність джерела явно позначається. Не підставляються performance metrics без перевіреного Campaign binding. Контекст містить обмежені факти, source pins, SHA-256 digest, редагування email/phone за Kernel redactor, видалення URL з текстових полів; довільний текст залишається untrusted. Raw owner IDs, credentials, account IDs, destination URL та media не передаються. Query нічого не відправляє назовні.

Marketing-owned runtime port визначає межу майбутнього advisory adapter. Recommendation policy приймає лише bounded text, точний context hash та evidence aliases із виданого контексту, відхиляє action payload, fabricated evidence, privilege fields. Результат не є proposal receipt або дозволом запуску. Durable independent review і live Hermes acceptance ще не підключені; fake provider чи автоматичного fallback немає. Для передачі фактичного контексту назовні потрібні окремі правила допуску й приймання. Додано unit та native MySQL context tests.

## M2c.5b: Адаптер зовнішнього середовища Hermes

Native Hermes Agent API adapter використовує окремий named profile `/p/{profile}/v1/chat/completions` і Bearer credential через Platform Credential Vault. Перед POST виконується `GET /v1/toolsets`; enabled toolset з інструментами або невідома форма відповіді блокує turn. Профіль має належати конкретному tenant та мати окреме operator acceptance із зовнішньою ізоляцією, вимкненими tools, memory, skills і обмеженим egress. Toolsets check не є доказом sandbox isolation. System prompt не надає гарантії від виконання інструментів.

Adapter повторно збирає authoritative scoped context до і після remote turn, передає лише facts, aliases, constraints та context hash, не надсилає record IDs або credentials у prompt. Використовує стабільний Idempotency-Key, TLS, bounded non-streaming response, без redirect і automatic retry. Приймається лише completed assistant response з JSON, validated evidence aliases і runtime receipt ID. Не є Finance approval або business Action.

Офіційний контракт: https://hermes-agent.nousresearch.com/docs/user-guide/features/api-server. Mock transport і native MySQL fixtures перевіряють profile scope, toolset gate, payload minimization, response validation, stable identity. Adapter навмисно не зареєстрований як live service/Capability: потрібні підтверджені runtime cost controls, independent review та реальне перевірене ізольоване середовище Hermes. Немає фактичного зовнішнього запуску.

## M2c.5c: Допуск і журнал виконання зовнішнього аналітика

`MarketingAnalystExecutionService` перевіряє tenant manager, module activation і operator-provisioned admission policy (disabled by default). Для дозволеного профілю обов'язкове посилання на прийнятий зовнішній cost-control policy; це не підтвердження фінансового бюджету або фактичної витрати. Реальний hard monetary cap і незалежна перевірка конфігурації Hermes залишаються gate до live binding.

Native MySQL admission counter серіалізує запуски на tenant і лімітує 1–100 admitted runs за UTC-добу. До remote call атомарно записуються STARTED, idempotency key, request/context hash, actor, profile, source revision pins і quota slot. Транзакція не утримується під час remote turn. Replay повертає той самий receipt без нового зовнішнього запиту; конфлікт ключа блокується. Незавершений STARTED блокує нові admission до контрольованого reconciliation (operator interface ще відсутній). Timeout, invalid response або stale context зберігаються як UNCERTAIN без raw error та без повернення quota slot. SUCCEEDED містить validated durable advice, runtime response ID і source evidence, відновлене з native context. Стан SUCCEEDED означає тільки завершення advisory turn, не approval. Review та recovery реалізовані в M2c.5d; немає автоматичного retry.

Service, runtime та policy не зареєстровані як live endpoint/Capability. Нова таблиця не замінює Platform Workflow, Kernel LLM governance або Finance ledger. Unit та native MySQL fixture tests перевіряють admission, replay, quota, uncertain outcomes, tenant boundaries і durable receipts.

## M2c.5d: Незалежний перегляд і ручне відновлення

`marketing.analyst.run.list/review/recover` — typed native Query та human-only internal Commands. Campaign Workspace відображає paginated журнал, source pins, advice, receipt та незмінне рішення незалежного менеджера. ACCEPT повторно перевіряє context hash, блокує Marketing-owned source revisions на час транзакції; Product facts є поточним спостереженням, не гарантією майбутнього стану. REJECT дозволяє відхилити застарілу пораду. Рішення не запускає Workflow, Meta або Finance.

STARTED та UNCERTAIN без рішення recovery блокують нові admission. Через 15 хвилин незалежний менеджер може зафіксувати ручне відновлення тільки після перевірки зупинки локального worker та remote Hermes process, із reason та зовнішнім evidence reference. Це operator attestation, не автоматичний доказ remote termination. Результат залишається UNCERTAIN, слот квоти не повертається, пізня відповідь не може перезаписати receipt. Повторний той самий decision key повертає існуюче рішення; інша дія блокується. Live запуск Hermes залишено неввімкненим до реального приймання і cost cap. Додано unit та MySQL tests.

## M2c.5e.1: Конфігурація та гранична вартість зовнішнього аналітика

`COS_MARKETING_HERMES_PROFILES_JSON` — deployment-only tenant map, за замовчуванням порожня. Один окремий profile URL та opaque Vault reference на tenant, evidence refs для ізоляції й provider hard cost cap, expiry (до 30 днів), 1–100 turns/UTC day, positive USD ceiling до 10.0000 за turn. Зміна будь-якого поля змінює configuration hash. Evidence refs є operator attestations, не автоматичним доказом remote safety. Немає UI setter або довільних URL із request.

`KernelBudgetedHermesAnalystRuntime` використовує існуючий `LlmGovernanceRepositoryInterface`: explicit USD budget required, native atomic reservation та settlement ДО remote call. До загального LLM ledger записується консервативний approved ceiling із `cost_source=AUTHORIZED_CEILING`, без вигаданих tokens/actual cost. Це budget consumption estimate, не інвойс, не Finance Expense і не виміряна вартість. Невизначений результат не повертає allowance. Помилка резервування або persistence не допускає виклик. Кореляція прив'язана до durable run receipt. Реальний hard cost cap має бути забезпечений зовнішньою конфігурацією Hermes/provider і прийнятий незалежно.

`marketing.analyst.runtime.status` — read-only локальна діагностика без передачі даних або розкриття secrets. Workspace показує конфігураційний статус та ceiling. `marketing.analyst.run.request` декларує external, approval-required, idempotent Action contract з pinned context і configuration hash; handler підключається у M2c.5e.2. Factory не доступна для direct UI/API execution. Remote execution і production readiness не заявлено.

## M2c.5e.2: Canonical Action binding та Goal proposal

MarketingDomainModule реєструє один external Action `marketing.analyst.run.request` і ApprovalRequired Policy. Handler приймає тільки RUNNING USER Action з canonical `wf:` key, повторно застосовує WorkflowActionWorkerAdmission (persisted first attempt, pinned approved Plan, Policy, independent Action Approval), re-resolve active identity/membership, module activation, config hash і context hash до durable admission. Не створює власний worker. SUCCESS означає лише persisted validated advice; невизначені результати не є success і не retryable.

Campaign Workspace дозволяє **propose** one-step Goal/Plan за наявності accepted tenant config, explicit LLM budget і canonical rollout allowlist. POST захищений CSRF; запуск, Approval і progression залишаються у COS Goals/Platform Workflow. Не існує direct external execution через Capability Gateway. Run-scoped outcome provider re-attests canonical Action receipt та читає Marketing run by Workflow idempotency key, campaign, actor і context hash. Criterion `marketing.analyst.advice_recorded` вимірює тільки факт отриманої рекомендації, не її review або Meta launch. Runtime status не заявляє live verification. Реальна ізоляція та remote cost cap потребують незалежної перевірки, end-to-end staging ще не виконано.

## M2c.6a: Наскрізна перевірка канонічного виконання

`tests/integration/marketing_analyst_approved_goal_e2e_mysql.php` використовує real MySQL Goal Store, Platform Workflow, Action repository/worker admission, Policy, Approval repository, Marketing owner records та Kernel LLM budget. Незалежне Plan Approval evidence створюється як SQL fixture (як у Product approved Goal E2E); Action approval проходить repository. Identity resolver, credential vault, Product read port і Hermes HTTP transport тестові. Тест перевіряє заборону unapproved Plan/Action, replay, один remote turn після списання ceiling, native receipt, Workflow checkpoint, незалежний Goal outcome, human review і tampered context. Не є live Hermes acceptance, не підтверджує provider isolation/cost, не запускає Meta. CI gate додано в runtime workflow; native MySQL E2E пройшов на commit 7f9f09a1.

## M2c.6b: Операторська перевірка реального профілю

Команда `cos:marketing:hermes-preflight <organization> [--probe]` виконує локальні перевірки module, operator configuration expiry, canonical rollout, explicit USD budget, Vault credential. Лише з `--probe` надсилається один authenticated HTTPS GET на `/p/<profile>/v1/toolsets`. Немає POST, генерації, запису бюджету, зміни бізнес-стану або автоматичного приймання. Спільний ProfileProbe використовується також перед реальною генерацією. Реєстр відхиляє спільний URL/credential reference між tenant та default profile; перевірка не доводить відмінність фактичних секретів, memory, skills, filesystem або egress isolation. Не допускається трактувати JSON object як empty toolset list.

Перед live acceptance оператор має підготувати окремий staging tenant та named Hermes profile, обмежити egress і memory/skills/tools, підтвердити hard provider cap та окремий API key. Не відкривати існуючу внутрішню staging network автоматично; доступ дозволяти тільки контрольованим каналом до Hermes. Передати `COS_MARKETING_HERMES_PROFILES_JSON` і referenced Vault secret через захищену конфігурацію PHP/worker, не через git або CLI arguments. Виконати local та remote preflight, потім створити синтетичну Campaign/Creative у Workspace, незалежно погодити Plan і Action, виконати canonical Workflow один раз, звірити native receipt, Kernel ceiling, provider usage та незалежний Goal outcome; окремий менеджер перевіряє advice. Невизначена відповідь не повторюється. Навіть успішний preflight не є live generation acceptance або дозволом release. Реальний staging запуск не здійснено.

## M3.1: План фінансування платного розміщення

Marketing зберігає immutable funding candidate для однієї Campaign/AdSet та read-only Meta Account з pinned revisions і AdSet content hash. UTC window 1–31 днів, P0 валюти USD/EUR/UAH/PLN; потреба lifetime budget = exact minor units планової денної суми × число днів. Це планова максимальна сума для майбутнього provider lifetime cap, не доказ обмеження Meta або фактична витрата. Caller не задає суму, currency, approval або provider ID. План не є provider-ready payload.

`marketing.paid.launch_plan.create/get` та `marketing.paid.funding.check` — typed internal command/read queries. Finance envelope повинен мати source_domain=marketing, source_ref=ID funding candidate, currency та період, що покриває план. Reservation має бути RESERVED з точним operation key `mkt-paid:<plan-id>:activate`, lifetime minor amount і Finance Action ID. Adapter використовує тільки FinanceBudgetControlInterface, перевіряє module activation та envelope revision до/після читання. F1 список обмежений 500 записами: відсутність рядка не вважається резервом. Source pins re-read до/після Finance observation. Результат `RESERVATION_OBSERVED_NOT_AUTHORIZED` не є атомарним допуском: race з release/freeze залишається, тому Meta mutation gate продовжує блокувати всі writes. Наступний пакет має реалізувати Finance-owned execution claim/release fence, provider lifetime cap і незалежний Workflow approval. Ніяких автоматичних Finance writes.

## M3.2: Атомарний фінансовий допуск

Finance реалізує one-shot exposure claim під тими самими locks, що й release. Окремий approved application service перевіряє canonical activation Action, pinned plan hash, envelope/reservation/revision. Після claim заборонено повторно видати execution authority або звільнити hold без незалежного фінансового settlement. Freeze блокує майбутні допуски, не обіцяє скасування in-flight provider request. Marketing write gate залишається закритим до інтеграції adapter, lifecycle та зовнішнього hard-cap acceptance.

## M3.3–M3.6: Провайдерські примітиви та журнал операцій

`MetaPaidWriteAdapter` має окремий write transport для Graph API v27.0, scoped Vault credential, fixed host, bounded request/response, no redirects/retries. P0 primitives: Campaign OUTCOME_TRAFFIC, один AdSet з lifetime budget та UTC schedule, Ad на незалежно прийнятому existing Creative; всі створення PAUSED. Readback перевіряє exact account/object. Status primitive змінює тільки ACTIVE/PAUSED. Цей обмежений сценарій не застосовувати до housing, credit, employment, political/social issues чи інших special-category ads; повноцінне підтвердження category, Creative, Page та landing URL ще необхідне.

`MysqlMarketingPaidOperationJournal` забезпечує durable STARTED/CONFIRMED/UNCERTAIN per plan/operation, stable Action/request hash, provider ID до readback, CAS finish та no replay. `MarketingPaidLifecyclePolicy` задає порядок створення й активації, перевірку paused hierarchy, lifetime cap, schedule, parent/creative references і точне зіставлення campaign-grain spend у UTC. Це окремі технічні компоненти, НЕ live canonical orchestration. У наступному підпакеті підключаються лише через canonical Workflow handler із порожньою deployment configuration за замовчуванням. Перед інтеграцією handler потрібні перевірка повного складу remote hierarchy (включно зі сторонніми AdSets), незалежний provider cap/creative acceptance, atomic Finance claim перед активацією, контроль drift, аварійна pause та reconciliation unknown outcomes. Source comparison не settlement; release claimed hold поки неможливий без фінансового приймання.

## M3.4–M3.5: Канонічний життєвий цикл платної реклами

Три external action contracts з незалежними Plan/Action Approval, current tenant membership, pinned plan/configuration hashes та one-shot journal. `create_paused` створює рівно один Campaign, AdSet, Ad, зберігає окремі receipts та перевіряє paused hierarchy. `activate` вимагає окремий explicit rollout flag, exact Finance reservation revision і one-shot exposure claim; активує дітей під paused parent, повторно читає весь склад і cap, потім активує Campaign. `pause` не вимагає актуального Creative чи активного Finance envelope; не звільняє hold і не доводить припинення billing. Одночасні команди та повторні POST блокуються. За невизначеного стану потрібна незалежна ручна звірка, зокрема аварійний stop в Ads Manager. Повторна активація цього P0 plan після pause не підтримується.

`COS_MARKETING_META_EXECUTION_PROFILES_JSON` за замовчуванням порожня; `COS_MARKETING_META_ACTIVATION_ENABLED` false. Профіль має окремий write credential, accepted existing Creative, explicit general-retail, exclusive-account control і billing-cap evidence refs, expiry до 30 днів. Ці refs є операторськими attestations, не автоматичним доказом hard cap або відповідності Meta policy. Незалежне live staging приймання, перевірка remote response shapes, drift, фактичного billing cap і worker timeout обов'язкові до ввімкнення. P0 лише UTC future traffic/automatic placements, один AdSet/Ad. Не використовується для special categories. Ручний emergency pause залишається обов'язковим при невизначеній зовнішній операції. Жоден production write не виконаний.

## M3.6–M3.7: Звірка джерела й робочий простір

`marketing.paid.operation.status` повертає локальні journal states та configuration fingerprint без secrets і без live claims. `marketing.paid.spend.compare` використовує лише підтверджений native Campaign binding і власний Marketing spend snapshot exact account/campaign/currency/UTC/window. Порівнює decimal source spend з plan minor cap, не підтверджує invoice, Expense, settlement чи повернення резерву. Claimed Finance funds залишаються RESERVED до окремого фінансового settlement з незалежно перевіреною billing evidence.

Campaign Workspace дозволяє створити funding candidate, вибрати Finance references, переглянути журнал, подати окремі Goal/Plan proposals для create_paused, activate, pause та порівняти source spend. Ніякого direct provider write. Run-scoped Goal outcome provider перевіряє canonical Action attestation, exact plan/request hash та native historical command receipt. Це доказ recorded configured state, не результат реклами. Live activation disabled by default; unknown result вимагає operator reconciliation; якщо конфігурація недоступна або минула acceptance expiry, аварійна pause виконується уповноваженим оператором в Ads Manager. Незалежне staging acceptance та реальні billing documents ще відсутні.

## Повторний контроль перед активацією

Finance повторно перевіряє активний exposure під власними locks безпосередньо перед parent activation. Provider readback порівнює країни, optimization, billing, bid і destination. Freeze після останньої локальної перевірки не гарантує скасування вже відправленого HTTP-запиту. Аварійне призупинення й незалежне staging acceptance обов'язкові.

## M3.7: Наскрізне приймання з ізольованим провайдером

`tests/integration/marketing_paid_approved_goal_e2e_mysql.php` перевіряє три окремі canonical Goal/Plan/Action/Outcome cycles (create paused, activate, pause), реальний MySQL journal, native Finance claim та блокування release. Plan Approval evidence й початковий Finance reserve є test-local fixtures; Action approval та worker gates реальні; Meta transport/identity/Vault isolated. Перевіряються відмова до погодження, точна кількість writes, parent/child activation order, повторне виконання, відсутність Hermes call та утримання резерву після pause. CI результат очікується; жоден Meta API request не здійснюється.

## Надійність імпорту та операційна черга

Meta read-only sync об'єднує імпорт Campaign/AdSet/Ad, Insights, `last_synced_at` і запис `SUCCEEDED` в **одну MySQL-транзакцію**. Невалідний рядок Insights або помилка посеред import викликає rollback без часткових campaign/metrics; окремий `tests/integration/marketing_meta_atomic_mysql.php` перевіряє це на справжній MySQL.

Якщо provider read або database import не завершилися, синхронізація залишає попередній успішний snapshot без змін, записує окрему `FAILED` спробу з **лише** санітизованим кодом `REMOTE_READ_FAILED` або `IMPORT_REJECTED`; raw API exception, access token чи provider URL не зберігаються. Якщо DB повністю недоступна, сам failure receipt також може бути недоступним; оператор отримує server error, але система не видає успіх. Дані Meta не модифікуються.

`/marketing/actions` показує останні спроби, посилання на account і операційну рекомендацію, включно зі станом `STALE_OR_NO_SYNC` після 24 годин від **останнього успішного синку**, без тверджень, що звіти за всі періоди свіжі. `marketing.paid.sync.history` доступний як tenant-safe read-only canonical Query. Ці receipts **не** є повним M3 external-mutation incident ledger.

## Рівні готовності та обмеження

- **M0**: SQL, сервіси, контракти, початкові тести; приймання D2–D5 ще не доведене.
- **M1**: Meta read-only адаптер і збереження спостережень, але без підтвердженого реального рекламного акаунта та Ads Manager reconciliation.
- **M2a/M2b**: Creative revisions і незалежний review реалізовані; Product live draft check підключений до сервісів Product Management. Hermes delegation, stock-by-location, destination/policy validation та production paid launch ще не підключені.
- **F1/M3**: Finance F1 уже в `main`; Marketing Meta writes залишаються заблокованими до окремого canonical Workflow, live evidence і staging acceptance.
- **M4/M5**: M4 точна атрибуція Sales і джерельна аналітика реалізуються окремим пакетом; multi-touch, Shopify orders і оптимізація потребують наступного приймання.

Початкова модель прав використовує `cos.tenant.access` та `cos.tenant.manage`; окремі `marketing.*` permissions необхідно додати до ширшого розгортання. У таблицях зберігаються лише непрозорі посилання на облікові дані, що розкриваються Platform Credential Vault під час дозволеного запиту.

Критерії подальшого приймання й контрольні етапи визначає `COS-MKT-TZ-001`.


## Читання джерельних рекламних спостережень (DAS-001)

`marketing.paid.spend.evidence.get` є tenant-scoped read-only Capability для перегляду збереженого source snapshot Meta на рівні кампанії. Сервіс перевіряє tenant, рекламний акаунт і snapshot identifier, повертає source hash, revision, window, timezone, currency та reported spend. Відповідь позначена `MARKETING_DB_SNAPSHOT` і `finance_expense_confirmed=false`.

Цей контракт **не** підтверджує рахунок, витрату Finance, факт оплати або незалежну звірку з Meta. Будь-яке перенесення до Finance потребує окремого погодженого cross-domain port, source pinning та Finance-owned reconciliation.

Після завершення періоду платної кампанії Workspace передає перевірений документ і ревізію рекламних витрат до Finance. Фінансовий Goal потребує окремого погодження, а його результат читається з нативного Finance ledger. Це не підтвердження оплати або бухгалтерської витрати.

## M4 — атрибуція та аналітика

Модель `utm-exact-v1` прив'язує нативний Sales Lead лише за точним `utm_campaign` (ID кампанії або конкретного paid plan), з окремими source/medium. Для Meta plan необхідні `meta` та `paid_social`. Повторний запис того самого джерела ідентичний; зміна UTM не перепризначає атрибуцію. Звіт повторно читає нативний Lead та його двосторонньо зв'язану угоду, не переносить персональні дані, відокремлює відсутні й змінені джерела. Підтверджені виграні угоди та їх суми не є бухгалтерською виручкою. Meta spend і Finance settlement відображаються окремо, без змішування валют, без оцінки ROAS або прибутку з неповних даних. Обмеження: когорта за зареєстрованими джерелами, не повний збір конверсій; до 500 записів у вікні до 91 дня. Shopify orders, автоматичний збір та multi-touch потребують окремих перевірених джерел.

У Performance Workspace доступні вибір кампанії/paid plan, генерація UTM, bounded discovery, фіксація доказів із ключем ідемпотентності та звіт із джерелами. Показники вартості за захопленим Lead/виграною угодою не є повними CAC або CPL. Пошук переглядає лише сторінку нативних Sales записів і не засвідчує повноту атрибуції. Виправлення вихідного UTM не переписує історичний доказ.

Медіааналітика M4: окремі повні bounded read-only імпорти Meta на рівні account/campaign/adset/ad, незмінна квитанція точного періоду та ревізій, без змішування рівнів. Порівняння тільки однакових за тривалістю неперетинних вікон тієї самої ревізії акаунта й attribution hash. Reach та provider action categories не сумуються як унікальна аудиторія або підтверджені конверсії. Доступні окремі `marketing.analytics.media.sync` та `marketing.analytics.media.report`. Порожня відповідь джерела не є доказом відсутності доставки.

Workspace Performance дозволяє виконати read-only імпорт медіа за конкретним зерном, відкрити незмінний звіт і порівняти рівні за однаковою тривалістю періоду. Секрети провайдера не повертаються. Ідемпотентне повторення повертає саме квитанцію, збережену в MySQL, а не повторний зовнішній запит.

Зворотний зв'язок Sales перевіряється через `inbound_request_id` нативної угоди та `client_case_id` нативного ліда. Додаткові заявки, приєднані до тієї самої угоди без канонічного inbound request, не дублюють її вартість між кампаніями.

## M4.10 — Атрибуція комерційних замовлень

Sales-owned placement v1 and optional Shopify last-visit UTM journey are read through `MarketingCommerceOrderReadPort`. Source absent => unavailable (not zero orders). The `commerce-utm-v1` model accepts exact canonical campaign/plan tags; paid plans require `meta/paid_social` and visit within the plan dates. Shopify visit precedes placement by no more than 30 days; native operator tags remain operator evidence. One assignment per native order, immutable capture and exact tenant source readback. Changed or conflicting evidence is excluded, not reassigned. Report totals are placed commercial order values per currency, not paid or recognized revenue; Deal and Order results remain separate until cross-entity deduplication is established. Finance O4 payment/refund evidence is required for cash/revenue reporting. No Shopify-specific coupling inside Marketing. Human batch capture 1–50, report max 500; automated capture and cross-branch acceptance are pending.

Finance boundary: optional `CommerceOrderFinancialEvidenceReadPort` is used only for independently confirmed collection facts. Currency, order/tenant identity, source revision and exact capture/refund/chargeback arithmetic are checked. Partial coverage is explicit; net collected total is provided only for the complete captured cohort, not the whole store. Accounting revenue, profit and ROAS remain unknown. No caller-supplied finance evidence or provider `PAID` inference.

## M4 — Контрольований збір конверсій

`marketing.attribution.sweep` є canonical internal Action з незалежним погодженням та live tenant identity. За один запуск сканує 1–5 сторінок по 50 нативних Lead або Order ID (до 250 записів) у заданому вікні (1–91 день), використовуючи exclusive keyset cursor і Sales-owned read port. Marketing повторно перевіряє точні UTM, source identity та вікно; запис і незмінна квитанція атомарні через існуючий attribution store. Повтор того самого Workflow key не повторює читання. Невідповідні, відсутні або суперечливі докази не зараховуються. `recorded_or_already_present` не означає нових конверсій. Вичерпання сторінки не є повним census: нові записи або пізні Shopify journeys вимагають повторного sweep. Paid plan scope задається явно; null означає exact campaign UTM, не всі paid plans. Рекламні й фінансові мутації відсутні. Наступні сторінки в межах погодженого бюджету обробляються автоматично; квитанція містить кількість сторінок і exclusive continuation cursor. За межами бюджету необхідний новий погоджений Action. Постійний scheduler, multi-touch та незалежне приймання ще потрібні.

Критерій `marketing.attribution.sweep_recorded` призначений для незалежного readback точного завершеного canonical Run та його native capture receipt. Він вимірює виконання bounded scan, а не кількість нових конверсій, повноту покриття чи фінансовий результат.

Інтеграційний тест `tests/integration/marketing_analyst_approved_goal_e2e_mysql.php` додатково перевіряє canonical Plan (approval evidence fixture), незалежне погодження Action, 51 нативний Lead на двох сторінках, відсутність мутації до погодження, повторний worker, native outcome readback та відмову при підміненому request hash. Це не доказ live approval або production scheduler.

## M4.11 — Моделі атрибуції історії контактів

`marketing.attribution.journey.get` читає окрему Sales-owned immutable history `shopify-journey-moments-v1` (не змінює last-visit evidence). Shopify readback приймає лише ready, точний momentsCount, повністю отриману сторінку до 100 унікальних CustomerVisit, узгоджені first/last pins; обмеження 30 днів. Невідомі UTM зберігаються як null без сирих ідентифікаторів, URL чи PII. First-touch, Last-touch і Linear розподіляють розміщену вартість в exact minor units; невідомий контакт зберігає свою частку, вона не перерозподіляється. Теги paid plan перевіряються за поточним нативним планом і датою контакту. Результат моделювання не є revenue, cash, causal inference або новим записом продажу. Відсутність або конфлікт історії означає unavailable. Історії понад 100 контактів потребують подальшого paginated import; native Lead/Order не мають повної джерельної історії. Live Shopify acceptance не виконано.

## M4 — Економічні показники підтвердженої когорти

`marketing.analytics.economics.report` читає exact paid plan, native Sales/Commerce cohorts і Finance settlement. Finance committed minor є незалежно підтвердженим бюджетним settlement, не оплатою або бухгалтерською витратою. Доступні cost per captured lead, cost per qualified lead, cost per won deal, cost per placed order та net collected / settled media spend для повної фінансової когорти в одній валюті. Поточний net collected може включати пізні повернення та chargeback; це не cashflow за календарний період. CAC, ROAS, ROMI, profit залишаються null до джерела унікального покупця, визнаної виручки, собівартості та повноти атрибуції. Не змішуються Deal і Order values. Відсутність settlement не є нульовою витратою.

## M5.1–M5.2 — Моніторинг та виявлення аномалій

`marketing.analytics.monitor.get` порівнює дві native complete source batches, рівної тривалості, non-overlap, same account revision/currency/timezone/grain/attribution scope. Перевірка на рівні однакових external IDs; відсутність рядка або недостатній обсяг не означає нульову доставку. `monitor-v1` використовує мінімум 1000 impressions і 20 clicks для CPC, падіння CTR ≥20%, зростання CPC/CPM ≥25% та зростання spend ≥25% при падінні clicks ≥20%. Це детерміновані сигнали для незалежного перегляду, не статистична значущість, не причинний висновок, не provider write або бюджетна рекомендація. Live provider acceptance, scheduled monitoring, experiments and Learning Loop pending.

## M4 — Плановий збір та продовження

Scheduler COS за замовчуванням вимкнено (`COS_MARKETING_ATTRIBUTION_SCHEDULER_ENABLED=0`). `COS_MARKETING_ATTRIBUTION_SCHEDULER_TARGETS_JSON` задає до 20 явних профілів: `profile_id`, `organization_id`, `actor_id` (string), `campaign_id`, `plan_id` (null або canonical ID), `source_kind`, `lookback_days` (1–30), `max_pages` (1–5). Інтервал tick 5–1440 хв, default 15. Live identity, modules Marketing/Sales та canonical rollout перевіряються кожного tick. Один native schedule row per tenant/profile, конфігурація pinned; зміна scope вимагає нового профілю та операторської звірки. Щоденний UTC rolling window завершується вчора, щоб повторно збирати пізні докази; paid plan обмежується затвердженим періодом, source kind order включає до 30 днів після завершення plan для пізніх конверсій. Незавершений цикл не скидається. Scheduler створює один immutable Goal/Plan, запитує незалежне Plan approval через Federation, запускає та просуває лише approved canonical Run. Незалежне Action approval не обходиться, бізнес-операцію виконує наявний worker. Наступний cursor читається виключно з attested native receipt. При terminal failure, rejected approval, revoked actor або scope drift Scheduler не створює новий Action автоматично. Після завершення поточного циклу наступний UTC day починається з null cursor, без заяви повного census. No provider or financial mutation. Live activation and scheduler E2E pending.

## M5.3 — Analyst із performance evidence

Існуючий `marketing.analyst.context.get` та approved `marketing.analyst.run.request` приймають опційні парні `paid_plan_id`, `snapshot_ref` з exact campaign/AdSet revision. Перевіряється нативний підтверджений deployment journal, campaign-grain snapshot, незмінний paid plan і поточна ревізія account, точний UTC період та валюта. У Hermes передаються лише агреговані impressions, clicks, CTR/CPC/CPM, reported spend і плановий cap. Native plan hash та snapshot revision/hash є частиною approved context; зміна даних до, під час або при review блокує advice. Без парних pins legacy planning context не змінюється. Advice не authorizes launch, Finance, budget mutation або provider write. Реальний Hermes acceptance і автоматичний performance plan initiation потребують окремої перевірки.

M4 source isolation: optional Shopify last-visit and moments reads are separate from commercial order import; protected-data errors leave attribution unavailable, not Sales placement blocked. Exact store/order identity and createdAt are revalidated. Full history is admitted only when its last-touch projection agrees with the separate last-visit observation. Tests cover optional scope denial without losing native Order or prior evidence.

## M5.4 — Попередній розрахунок оптимізації бюджету

`marketing.optimization.budget.preview` порівнює два різні завершені native paid plans одного акаунта, валюти, ревізії та UTC періоду, із підтвердженим terminal lifecycle і різними provider campaign IDs. Ціль першої моделі `TRAFFIC_CPC`, не ROAS. Мінімум 1000 impressions і 20 clicks на кожен план, позитивний spend, явний поріг гіршого CPC ≥125% кращого. Перенесення максимум 10% добового ліміту кожного плану з дорожчого у дешевший, total daily cap зберігається в integer minor units. За відсутності зіставності/доказів або при перевищенні source cap зміни не пропонуються. Результат лише hypothetical future planning candidate, нові draft, Finance reservation, Meta mutation, статистична значущість і прогноз конверсій не створюються. Новий запуск потребує чинних Product/Creative/Audience pins, Finance і незалежного Workflow approval. Thresholds are provisional engineering defaults, not learned coefficients.

## M4 — Уточнення меж атрибуції та хронології

Платний Lead зараховується лише в календарному періоді відповідного незмінного Paid Plan; пізніше скопійовані UTM не приймаються як нові докази залучення цим планом. На звіті попередні записи перевіряються повторно. WON Deal не враховується, якщо його закрито раніше від точного часу залучення Lead, включно з тією самою датою. Наявність пов'язаної угоди та кількість перемог дедуплікуються окремо. Не розраховуються ціни конверсій за неповної/застарілої вибірки. Це не створює нових джерел бухгалтерського доходу або наскрізної історії контактів.

## M5.5 — Попереднє порівняння гіпотез аудиторії

`marketing.optimization.audience.preview` порівнює два завершені native Paid Plans з однаковими account/revision, currency, attribution scope, звітним вікном, лімітами бюджету й hash креативу. Окремі Audience revisions з неперетинними наборами країн читаються через Marketing planning pins; результати є лише спостереженням campaign-grain CPC. За неповної вибірки або перевищення ліміту результат не визначає кращий план; статистична значущість, фактична доставка аудиторії та причинний ефект не підтверджуються. Немає рекомендації змінювати таргетинг, автоматичної мутації бюджету або обходу обмежень спеціальних рекламних категорій. M5.6 потребує незалежного контрольованого експерименту; цю модель не можна вважати A/B тестом.

## M4 — Пізні конверсії в економіці кампанії

Опційний `conversion_through` у `marketing.analytics.economics.report` продовжує лише часову межу закритих Deal і створених Order (від кінця Paid Plan до +30 днів, не в майбутньому). Початковий період залучення Lead і Finance settlement не розширюється. Звіт повертає окремі `window_end` і `conversion_through`; всі джерельні межі перевіряються. Shopify last-visit залишається обмеженим 30 днями від візиту до замовлення, а cash collection — поточний readback когорти, не обліковий дохід на вибрану дату. Немає повного census або заяви ROAS/CAC.

## M4 — Контрольна точка інженерного приймання

На SHA `a04b5a466654d7ba05e519cd87c521dc8bb320e8` усі п'ять workflow (CI, Runtime, Documentation, Frontend, L2 Recovery Smoke) завершилися успішно. Native MySQL fixtures підтвердили exact acquisition window, пізній WON Deal і Shopify Order в обмежений conversion cutoff, незалежні Plan/Action approvals, scheduler continuation та Finance settlement readback. Це D2, не live приймання Meta/Shopify або доказ повного census, історії контактів та визнаної виручки; CAC/ROAS/ROMI залишаються null до відповідних незалежних джерел.

## M5.6–M5.7 — Реєстрація експерименту та спостереження за креативом

`marketing.experiment.register` — internal idempotent command для immutable tenant-owned protocol із двома різними Paid Plans до початку їхнього UTC вікна і без записаних paid operation journal entries. Однакові account revision, currency, UTC період, daily/lifetime cap, audience revision, campaign objective/brief/Product refs та всі поля креативу, крім рівно одного фактора HEADLINE або PRIMARY_TEXT. Protocol pins та hash зберігаються в Marketing; унікальна невпорядкована пара планів забороняє повторну реєстрацію зі зміненими ролями. `marketing.experiment.get` повертає immutable readback і окремий прапорець актуальності джерел. Планове розподілення 50/50 не означає фактичний randomized split; protocol не створює Meta Experiment, не запускає кампанії, не змінює кошти і не є доказом pre-launch provider isolation.

`marketing.optimization.creative.preview` приймає тільки ID зареєстрованого протоколу та два exact snapshots у відповідних ролях. Після закінчення обох вікон читаються terminal paid lifecycle, revision/hash native source batch, спільний attribution scope та незмінні planning pins. Модель TRAFFIC_CPC використовує зафіксовані пороги 1000 impressions/20 clicks і ≥125% відношення CPC, без оцінки за overspend або неповних доказів. Підказка lower observed CPC не є переможцем A/B, statistical significance, причинним ефектом або рекомендацією активації. Необхідний незалежний readback provider randomization, mutually exclusive assignment та exposure/unique outcomes для контрольованого тесту. Позитивний policy fixture і native registration/replay/tenant/confounding fences покривають D2; реальне randomized acceptance і незалежне native спостереження двох завершених кампаній ще потрібні.

На SHA `631d7026fdbebbe30dab4b659f3f18c0eb64b4b0` Federation MySQL job 139746354586 пройшов native registration/replay, one-factor fences та відмову оцінювати майбутні плани за чужим snapshot. Це не підтвердження randomized experiment або спостереження двох завершених кампаній.

## M5.8 — Контрольовані правила оптимізації

`marketing.optimization.rule.evaluate` — read-only versioned rule evaluation для одного provider campaign, exact latest native batch IDs та двох завершених UTC вікон рівної тривалості без перетину. Підтримуються CTR_DECLINE (поріг 20–90%), CPC_INCREASE і CPM_INCREASE (25–200%). Потрібні minimum 1000 impressions в обох вікнах, CPC — 20 clicks; нульовий baseline denominator або відсутній рядок означає недостатні докази. Відносна зміна округлюється до шести знаків перед включним threshold comparison. Результат містить scope, rule, threshold, native source revision/hash, фактичну зміну, deterministic evaluation hash, status та review recommendation. Підміна batch ID, account revision drift або зміна evidence під час readback блокується. Немає довільних виразів, окремого executor, створення Action, Meta/Finance mutation або автоматичної зупинки бюджету. Наступний крок — canonical Goal/Plan propagation із незалежним approval, повторним evidence readback і Learning Loop.

## M5.9 — Ціль, погоджений запис перегляду та незалежний результат

`marketing.optimization.rule.plan.propose` створює immutable canonical Goal/Plan для одного exact triggered rule hash, Marketing account та provider campaign. До створення Plan перевіряється актуальність batch IDs та порогу, не виконується Action, не змінюються provider/Finance. Незалежний Plan approval та окремий Action approval належать Federation, Kernel і Platform Workflow. `marketing.optimization.rule.review.record` приймає тільки RUNNING canonical Action з активним tenant, live actor identity, workflow admission, exact schema і idempotency key. Повторне читання правила після approval має збігтися з погодженим evaluation hash, інакше Action відхиляється. Native immutable receipt прив'язаний до actor, Action id, evaluation, exact parameters, часу та receipt hash; один review per tenant/evaluation hash. `marketing.optimization.review_recorded` незалежно перевіряє завершений canonical Run і native receipt. Це підтвердження погодженого review, не виконаної оптимізації, provider change, uplift чи витрат. Live-provider acceptance, автоматичні тригери, реальні дії та довгостроковий learning залишаються окремими межами.

Попередня точка `382c03cd346d4a5933c7b8bb8196c2814932c534` пройшла всі п'ять workflow (CI, Runtime, Documentation, Frontend, L2 Recovery Smoke).

На SHA `3e47d92ef127f18185ad9d06b68b240473b6cbfb` Federation MySQL job 139759188421 пройшов реальні незалежні Plan та Action approvals, immutable review, stale evidence та duplicate fences і native Outcome.

## M5.10 — Цикл навчального спостереження

`marketing.optimization.learning.preview` читає історичний trusted Federation evaluation з точними Run, evaluation і receipt ID, критерієм та receipt hash. Минуле незалежне приймання review залишається історичним доказом після expiry старих approvals; воно не дає дозволу на нову дію. Наступний повний provider campaign batch мусить мати той самий account/revision, currency, attribution scope та тривалість UTC вікна; baseline — саме поточне вікно попереднього review. Source drift або підміна evidence відхиляється. Відсутні дані дають INSUFFICIENT_EVIDENCE, а не нуль. Розрізняються IMPROVED_OBSERVED, DEGRADED_OBSERVED і UNCHANGED_OBSERVED. Окремо позначається AFTER_REVIEW або RETROSPECTIVE_ONLY; спостереження не доводить causal effect, actual campaign change, experiment winner або training.

`marketing.optimization.learning.plan.propose` створює новий canonical Goal/Plan з exact learning hash; `marketing.optimization.learning.record` вимагає нові незалежні Plan та Action approvals і повторний native evidence readback. Immutable native receipt має one-per-observation fence, actor/action provenance і receipt hash. `marketing.optimization.learning.get` читає tenant-scoped native learning receipt, а `marketing.optimization.learning_recorded` засвідчує завершений canonical Run та receipt independently. Наступний крок — KEEP_OBSERVING, REVIEW_AGAIN або COLLECT_EVIDENCE; навчальна модель не змінює правила, provider, Finance або бюджет автоматично. Process Registry: `marketing.optimization-review-learning`. Реальне provider-backed prospective вікно та причинний ефект потребують окремих доказів.


## M4 — Повне bounded джерельне читання історії та дедуплікована економіка

Shopify CustomerJourneySummary moments читаються курсорами (до 5 сторінок по 100 CustomerVisit, exact count, повторний повний прохід для багатосторінкових історій, ідентичні endpoints і scope). Повторені ID, неповні/змінені сторінки та перевищення межі не створюють історію; незалежне last-touch читання та комерційна placement не блокуються. Sales зберігає лише нормалізовані hash-based visits, Marketing моделює First/Last/Linear до 500 контактів без PII; модель і hash для попередніх одиночних сторінок не змінені. Реальна готовність залежить від provider permissions, повного 30-денного вікна та source stability. Офіційний контракт: https://shopify.dev/docs/api/admin-graphql/latest/objects/customerjourneysummary.

Economics додає captured_unique_outcomes і cost_per_captured_unique_outcome лише коли native Sales/Order deduplication має повний captured cohort, точну кількість Lead/WON/Order, арифметику overlap та clean source pins. Не називаємо унікальний Deal/Order унікальним покупцем. Finance-owned accounting recognition, повний acquisition census, витрати fulfillment/COGS і верифікований customer identity необхідні для CAC/ROAS/ROMI. Відсутність цих джерел не підміняється нулем, cash або placed value.

## M5.1–M5.2 — Автоматизований моніторинг і canonical review

Scheduler відключений за замовчуванням (`COS_MARKETING_OPTIMIZATION_SCHEDULER_ENABLED=0`, interval 5–1440 min, default 15). `COS_MARKETING_OPTIMIZATION_SCHEDULER_TARGETS_JSON`: до 20 explicit profile_id, organization_id, actor_id, account_id, external_id, rule, threshold_percent, lookback_days (1–30). Same account needs same authorized actor; duplicate target/rule forbidden. UTC частини завершених рівних вікон завершуються вчора, native batch reuse або read-only import зі stable key, затем exact bounded evaluation. Тільки triggered сигнал породжує deterministic Goal/Plan. Незалежне Plan/Action approval, canonical Run, independently recorded Outcome; pending цикл не замінюється новим. Зміна конфігурації та terminal failure fail closed. Немає прямого paid mutation або фінансового дозволу.

### M5 — погоджений optimization pause

У Paid Funding Workspace (операція `pause`) менеджер може додати точні `review_run_id`, `review_evaluation_id`, `review_id` та `review_receipt_hash`.
Це лише пропозиція нового Goal/Plan. Перед її створенням і повторно у worker перевіряється незалежно записаний trusted Goal Outcome, точні актуальні native batches, ідентичний evaluation hash, той самий Meta campaign/account/revision/currency, активний UTC paid plan та завершене вікно не старше семи днів. Необхідні confirmed deployment/activation receipts, узгоджена ієрархія та відсутність уже розпочатого pause. Далі діють наявні окремі Plan та Action approvals, canonical worker admission, одноразовий Meta Pause і незалежний readback. Native command hash фіксує всі review-піни. Звичайний emergency/manual pause не потребує optimization review. Жодного автоматичного збільшення бюджету чи повторної активації; Finance-операції окремі. Production-перевірка Meta та причинного ефекту не виконана.
