---
title: COS Portfolio — поточна переоцінка
description: Єдиний портфель напрямів COS з операційними ризиками, доказовою готовністю і пріоритетами.
status: active
updated: 2026-10-22
kind: product
---

# COS Portfolio — актуальна переоцінка 22.10.2026

**Мета:** єдина шкала рішень щодо інвестицій у COS; не плутати merged, CI PASS, deployed, enabled, production-ready і verified business outcome. Попередній зріз — 17.10.2026, збережено в історії цієї гілки.

**Перевірено:** repo `Murakhovsky/TerraNova2026`, актуальний `main` до merge #407 (commit `9a9a832708b16c3ef0ba6f577a49938733617daf`), модульні manifests/generated reference, PR #353/#364/#367/#374/#388/#389/#390/#391/#392/#397/#401/#405/#406/#407, workflow results на HEAD окремих PR, operational docs. **Не перевірено з живим доступом:** стан VPS/запущених контейнерів, секретів, MySQL, реальних браузерних сесій, поточного deploy SHA, production health, історичних файлів/даних та незалежних двоакторних approvals. Це репозиторний аудит, не production-sign-off.

## Висновок

**Архітектура і код суттєво випереджають комерційно та операційно прийнятий продукт.** Наступний пріоритет — не нові пакети. Потрібен захищений operating baseline, один незалежно підтверджений Goal-to-Result та один повторюваний Real Estate pilot.

### Оціночна матриця (управлінська експертна оцінка, НЕ тестове покриття)

| Зріз | Орієнтир / 100 | Докази та обмеження |
| --- | ---: | --- |
| Архітектурна цілісність | 85 | Symfony canonical, modular monolith, Domain ownership, Workflow/Action/Approval, execution authority tests; залишились legacy compatibility та open supersession branches |
| Функціональна реалізація | 75 | Property/Sales/Real Estate, Growth, Capital Markets, Federation, Documents мають значний код, але feature flags, isolated acceptance |
| Автоматизована тестованість | 75 | CI + Runtime/Federation MySQL/L2, #388 full 10-Asset MySQL E2E, #407 PR-head workflows PASS; не є live-business acceptance |
| UX/готовність до самостійного використання | 55 | Робочі workspaces, admin settings; відсутні доведені повні human pilot journeys, recovery UX |
| Експлуатаційна готовність | 35 | Fresh MySQL на новому VPS, light/core 2 GiB tier, локальні backups і HTTPS за PR-описами; historical DB not recovered, offsite backup not configured, disabled specialized workers |
| Автономність бізнес-процесів | 35 | Canonical plan/approval/action/receipt, guarded AI/CRON proposal; немає human-free service principal delegation і live unattended workflow acceptance |
| Комерційна валідація COS | 15 | Немає в цьому аудиті незалежно зафіксованих активних платних COS-клієнтів, usage/ROI, pilot completion; відсутність доказу не означає відсутність клієнтів |

Цифри — **приблизні аналітичні індекси** для порівняння пріоритетів, не лабораторно виміряні completion %. Сумарний «% COS» не обчислювати без зафіксованого продуктового scope.

## Що змінилося з 17.10

1. [#388](https://github.com/Murakhovsky/TerraNova2026/pull/388) **merged 19.10**. Є canonical Capability Library; Property Bulk JSON/UTF-8 CSV до 10 записів; HMAC AI/CRON review intents; immutable batch; native Property writer через approved Workflow Action; persisted Goal outcome. Автоматизований full 10-Asset MySQL E2E проходив, але незалежний browser + дві реальні люди + deployed-tenant acceptance не доведені. XLSX/XLSM не підтримано.
2. [#390](https://github.com/Murakhovsky/TerraNova2026/pull/390) та [#392](https://github.com/Murakhovsky/TerraNova2026/pull/392) merged: у UI є gated ChatGPT commercial-partner OAuth. Без партнерського клієнта та credentials **Continue with ChatGPT disabled**. Не давати загального доступу до підписки замість API.
3. [#393–#406](https://github.com/Murakhovsky/TerraNova2026/pulls?q=is%3Apr+is%3Aclosed) — великий ops цикл AWS replacement host / GHCR / fresh MySQL / admin bootstrap / local backup. Ключове: #401 зафіксував **нову**, а не restored історичну базу. #405 задекларував локальні backup і відсутність offsite. #406 — створення першого admin, без розголошення секрету. PR-текст ≠ незалежно підтверджений production state.
4. [#407](https://github.com/Murakhovsky/TerraNova2026/pull/407) **merged 21.10**: OpenCode V2 admin-only test bridge через canonical LLM Registry. Enabled flag OFF за замовчуванням; no agent/tool/shell/file access; немає live API/configuration acceptance. Exact-head CI/Runtime/Frontend/Docs/L2 workflow success.
5. [#391](https://github.com/Murakhovsky/TerraNova2026/pull/391) (автодеплой після всіх main push) **досі OPEN**; `.github/workflows/runtime.yml` в main зберігає `push.paths`. Отже, суцільне автодеплоювання всіх змін не доведено.
6. [#389](https://github.com/Murakhovsky/TerraNova2026/pull/389) — цей Portfolio **досі OPEN** і не існує в main; цей перегляд оновлює його без втручання у production.

## Режими інвестицій: WIP ≤ 2

| ID | Напрямок | Mode 22.10 | Що вже є / наступний результат | Що блокує статус READY |
| --- | --- | --- | --- | --- |
| COS-OPS | Security, data, deploy, recovery, workers | **ACTIVE P0: recovery gate** | Піднятий core runtime / GHCR workflow; local backup mechanism | Audit фактичного live SHA/MySQL/backup restore, offsite backup, historical data decision, безпечна активація потрібних workers |
| COS-RE | Property / Sales / Real Estate + Goal pilot | **ACTIVE P0: one business vertical** | #388 merged; native real MySQL 10-object tests, canonical Workflow / Action / outcome provider | Live controlled 10-Asset operator journey, independent human approvals, tenant/evidence/replay checks, реальний CRM/Sales use case |
| COS-FED | Kernel + Federation / Goals / Workflow | **ACTIVE тільки як блокуюча залежність COS-RE** | #346/#383/#388 canonical path; explicit policy, store, receipt, projections | Немає окремого незалежного real-user pilot; Golden 50 з реальними Growth Candidates/Leads/Documents не підтверджено |
| COS-ENG | Engineering agents, tool execution, ops UI | **MAINTENANCE** | Durable runtime, existing tests; #353 open | Core tier disables specialized worker; #353 лише live UI polling, не лікує worker; release acceptance потрібне окремо |
| COS-LLM | AI providers, ChatGPT OAuth, OpenCode | **MAINTENANCE / manual experimentation** | #390/#392/#407 merged; admin LLM settings | SIWC needs partner access; OpenCode V2 manual admin-only API, default off, no production usage ledger |
| COS-GROWTH | Growth + CRM demand discovery | **MAINTENANCE** | Growth 0.50.0, Sales handoff pathways, default disabled | Real tenant flow, approvals, 50 unique real candidates and business results |
| COS-DOC | Documents | **MAINTENANCE** | Platform upload/version/template and gated signature requests | No independent real document legal-signature proof; 50 prepared proposals unverified |
| COS-UX | Experience V1 / localized UI | **MAINTENANCE** | #379/#381 merged | Production customer UX acceptance, accessibility and onboarding |
| COS-CM | Instrument Pool + Market Data + FOS | **GATED R&D** | #370/#377 in main, source-fenced L2/BBO, portfolio/risk/research | Licensing, real entitlements, live ≥24h soak, independently trusted 250 BBO/50 L2, resource-heavy specialized workers OFF |
| COS-CM-A | Capital Markets agent lifecycle | **FROZEN R&D** | #364 Draft with checkpointed recovery | Operator UI, approved paper-only execution, live quality/LLM/chaos evidence, economics |
| COS-SVC | Service / Diagnostic | **MAINTENANCE** | Runnable V1 modules | No business pilot evidence in this audit |
| COS-FUTURE | Finance, HR, Procurement, Construction, Tokenization | **FROZEN** | First four are installable 0.1.0 skeletons | Concrete paying user, owner, budget, regulatory review where relevant |

**WIP rule:** COS-OPS і COS-RE — єдині дві поточні активні роботи. COS-FED отримує зміни тільки для розблокування Real Estate scenario. Жодного нового «P0» в CM/FOS, tokenization або для експериментального LLM.

## GitHub queue станом на перевірку

| PR / issue | State | Рішення |
| --- | --- | --- |
| [#389](https://github.com/Murakhovsky/TerraNova2026/pull/389) Portfolio | OPEN | Оновити і закріпити управлінський source-of-truth після documentation checks |
| [#391](https://github.com/Murakhovsky/TerraNova2026/pull/391) full main auto-deploy | OPEN | Перевірити з поточним low-memory GHCR deployment, після цього прийняти/відхилити; не руйнувати environment gates |
| [#353](https://github.com/Murakhovsky/TerraNova2026/pull/353) Engineering polling | OPEN | Переоцінити на current main, застосувати тільки якщо актуально |
| [#367](https://github.com/Murakhovsky/TerraNova2026/pull/367) L2 recovery | OPEN DRAFT | Diff із #377, no duplicate merge; provenance і закриття, якщо fully superseded |
| [#374](https://github.com/Murakhovsky/TerraNova2026/pull/374) production pool | OPEN DRAFT | Diff із #377, залишкові інваріанти; external acceptance не знімати |
| [#364](https://github.com/Murakhovsky/TerraNova2026/pull/364) CM agents | OPEN DRAFT | Hold R&D |
| [#360](https://github.com/Murakhovsky/TerraNova2026/issues/360) Production Streaming | OPEN | Acceptance umbrella; development hold |
| [#386](https://github.com/Murakhovsky/TerraNova2026/issues/386) H1/H2 scan registry | OPEN | Frozen parity gap; no competing scheduler |

На 17.10 було 5 open PR, згодом #388 merged, #389 і #391 залишилися; на 22.10 **6 open PR і 2 Issues**.

## P0: критичні операційні докази, перш ніж покладатися на COS

1. **Read-only live audit:** deployed SHA/HTTPS, Docker/core state, health, MySQL schema/table count, live user/admin record, Redis/Messenger backlog, disabled feature flags, available memory/disk; без жодних credentials у звітах.
2. **Data preservation:** письмово розділити newly initialized DB та unrecovered historical data/master key/uploads; перевірити, чи можливе безпечне відновлення зі старих snapshots. Не називати «recovered» створення порожньої нової БД.
3. **Backups:** verified restore drill із локального `mysqldump` у окрему БД; налаштувати зашифрований offsite backup із retention; не публікувати encryption master keys у GitHub/Actions.
4. **Release control:** отримати фактичний статус #391 і повний `main → GHCR exact SHA → deploy core → smoke`. Всі worker/resource changes gated; full mode на 2 GB не активувати сліпо.
5. **Admin security:** перевірити первинний login та MFA/credential rotation там, де підтримується; ніколи не друкувати one-time пароль/файли.

## P0: комерційний vertical slice після operating baseline

**Definition of Done:** 10 справжніх об'єктів (або чітко відокремлені staging originals) імпортуються через UI/CSV/AI-CRON review, кожна тотожність дедуплікується, Plan і Action отримують **незалежні реальні approvals**, `Platform Workflow` виконує рівно одну бізнесову мутацію на ключ, 10 native Property records підтверджені read model і незалежним Goal outcome, негативні retries/error/reconciliation не створюють дубль. Далі принаймні один реальний Sales/Real Estate процес використовує внесені активи; фіксуємо elapsed time, ручні дії, quality, failures і бізнесовий outcome.

Автоматизований [#388](https://github.com/Murakhovsky/TerraNova2026/pull/388) E2E покриває MySQL, але використовує fixture для першого Plan approval. Це **не** замінює операторську двоакторну перевірку. Основний P0 тут — execution/verification, а не нова архітектура.

## Golden 50 і FOS

- **Golden 50:** залишити як release proof для ширшої cross-domain федерації. Без реальних native 50 Growth Candidates + 50 Sales Leads + 50 Documents + 50 незалежно атестованих результатів не ставити acceptance PASS. Не перетворювати його на блокер кожної малої 1–3 step Goal або першого Property pilot.
- **FOS:** не запускати continuous WS/Fleet/Live providers чи real trades на поточному core tier. #377 already merged; #367/#374 можуть мати пересічні зміни. Vendor contracts, entitlements, verified live 24h data/lease/failover і фінансові ризики — **окремий** проєктний бюджет і середовище. Відсутність доказу реальної економічної edge означає, що unit/paper P&L не створює production profitability claim.

## Зміна концепції розвитку

COS зараз найбільше потрібен **короткий доказ корисності у власному бізнесі нерухомості**, а не інший універсальний модуль. Перший пілот: «Додай 10 об'єктів → перевір → погодь → створи native records → виконай Sales follow-up → покажи результат». Готовий код достатній для переходу до операційного приймання після recovery gates; ресурс обмежує worker automation. За 14–30 днів успіх оцінювати **не PR count**, а безпечним recovery, real user journey, кількістю verified actions, часом операторської роботи та кількістю комерційно придатних об'єктів.

## Відповідальність і зміни

- **COS owner:** рішення ACTIVE/FROZEN, бюджет, target use case та бізнесовий sign-off.
- **Domain/Engineering:** перевірка code/quality/security, технічні receipts, no-shadow-writers.
- **Operations:** immutable deploy SHA, database backup, offsite, rollback, worker resource telemetry; release approvals.
- **Pilot operator:** справжня двоакторна approval, Property/Sales business evidence, UX issues, ROI.

Джерела: [main current scope](./current-scope.md) (деякі розділи застарілі, dated 2026-10-06), [generated capabilities](../12-reference/module-capabilities.md), [Docker lean deployment](../10-operations/docker-lean-deployment.md), [Golden 50 acceptance](../02-workflows/federation-golden-50-operator-acceptance.md), [Federation trusted outcomes](../03-architecture/federation-trusted-goal-outcomes.md), [OpenCode manual testing](../06-ai-agents/opencode-personal-testing.md).

**Цей документ — керівний портфель, а не дозвіл на автоматичний merge, schema changes, production activation, trading чи персональне використання підписки як API.**
