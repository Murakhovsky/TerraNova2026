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

**Стан:** початкова реалізація M0/M1, очікує перевірок на staging і користувацького приймання. Домін встановлюється вимкненим за замовчуванням. Реальні платні операції заблоковано.

## Розподіл відповідальності

- **Marketing**: маркетингові кампанії й чернетки, рекламні аудиторії, креативи, імпорт Meta Campaign/AdSet/Ad та спостережені Insights.
- **Growth**: Market Universe, Signals, OpportunityCandidate, Qualification, Outreach, Response Routing і Growth Learning. Жодного перенесення Growth даних не відбулося.
- **Product**: товари, варіації, доступність і ціни. Marketing зберігає лише посилання та не оголошує їх перевіреними до приймання Product API.
- **Sales**: первинні факти лідів, угод і продажів.
- **Finance**: грошові ліміти, резервування, справжні витрати, платежі та фінансова звірка. Показник `spend` із Meta — не бухгалтерський Expense.

Внутрішня Marketing Campaign може мати декілька зовнішніх PaidMediaCampaign. Імпортовані рекламні об'єкти залишаються `EXTERNALLY_MANAGED`.

## Фактично додані можливості

Двадцять вісім typed capabilities, включно з шістьма M2a, Product draft-check і Finance-facing source Query: `marketing.campaign.search`, `marketing.campaign.get`, `marketing.campaign.create`, `marketing.paid.account.list`, `marketing.paid.insights.query`, `marketing.paid.sync.history`. Для них діють Canonical Capability Gateway і перевірка tenant менеджера. Нових універсальних executor або CommandBus не створено.

На UI передбачено Overview, Campaigns, Campaign Workspace, Paid Media Accounts, Performance та Action Queue. Менеджер може створити внутрішню чернетку, під'єднати read-only Meta Ad Account через opaque Vault reference, імпортувати і переглядати provider observations.

Активація, створення рекламних кампаній у Meta, зміна бюджету та інші paid writes відсутні й навмисно блокуються. Для них потрібні Finance F1, незалежне погодження, canonical Workflow і провайдерний readback.

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

## Надійність імпорту та операційна черга

Meta read-only sync об'єднує імпорт Campaign/AdSet/Ad, Insights, `last_synced_at` і запис `SUCCEEDED` в **одну MySQL-транзакцію**. Невалідний рядок Insights або помилка посеред import викликає rollback без часткових campaign/metrics; окремий `tests/integration/marketing_meta_atomic_mysql.php` перевіряє це на справжній MySQL.

Якщо provider read або database import не завершилися, синхронізація залишає попередній успішний snapshot без змін, записує окрему `FAILED` спробу з **лише** санітизованим кодом `REMOTE_READ_FAILED` або `IMPORT_REJECTED`; raw API exception, access token чи provider URL не зберігаються. Якщо DB повністю недоступна, сам failure receipt також може бути недоступним; оператор отримує server error, але система не видає успіх. Дані Meta не модифікуються.

`/marketing/actions` показує останні спроби, посилання на account і операційну рекомендацію, включно зі станом `STALE_OR_NO_SYNC` після 24 годин від **останнього успішного синку**, без тверджень, що звіти за всі періоди свіжі. `marketing.paid.sync.history` доступний як tenant-safe read-only canonical Query. Ці receipts **не** є повним M3 external-mutation incident ledger.

## Рівні готовності та обмеження

- **M0**: SQL, сервіси, контракти, початкові тести; приймання D2–D5 ще не доведене.
- **M1**: Meta read-only адаптер і збереження спостережень, але без підтвердженого реального рекламного акаунта та Ads Manager reconciliation.
- **M2a/M2b**: Creative revisions і незалежний review реалізовані; Product live draft check підключений до сервісів Product Management. Hermes delegation, stock-by-location, destination/policy validation та production paid launch ще не підключені.
- **F1/M3**: Finance F1 уже в `main`; Marketing Meta writes залишаються заблокованими до окремого canonical Workflow, live evidence і staging acceptance.
- **M4/M5**: мультиджерельна атрибуція, оптимізація й додаткові провайдери не реалізовані.

Початкова модель прав використовує `cos.tenant.access` та `cos.tenant.manage`; окремі `marketing.*` permissions необхідно додати до ширшого розгортання. У таблицях зберігаються лише непрозорі посилання на облікові дані, що розкриваються Platform Credential Vault під час дозволеного запиту.

Критерії подальшого приймання й контрольні етапи визначає `COS-MKT-TZ-001`.


## Читання джерельних рекламних спостережень (DAS-001)

`marketing.paid.spend.evidence.get` є tenant-scoped read-only Capability для перегляду збереженого source snapshot Meta на рівні кампанії. Сервіс перевіряє tenant, рекламний акаунт і snapshot identifier, повертає source hash, revision, window, timezone, currency та reported spend. Відповідь позначена `MARKETING_DB_SNAPSHOT` і `finance_expense_confirmed=false`.

Цей контракт **не** підтверджує рахунок, витрату Finance, факт оплати або незалежну звірку з Meta. Будь-яке перенесення до Finance потребує окремого погодженого cross-domain port, source pinning та Finance-owned reconciliation.
