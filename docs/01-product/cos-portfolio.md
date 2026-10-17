---
title: COS Portfolio — єдиний реєстр напрямків і пріоритетів
description: Портфель продукту, технічної готовності, незавершених PR та рішень Active / Maintenance / Frozen.
status: active
updated: 2026-10-17
kind: product
---

# COS Portfolio — керування напрямками

**Baseline audit:** 2026-10-17. **Repository:** [Murakhovsky/TerraNova2026](https://github.com/Murakhovsky/TerraNova2026), default branch **main**.
**Призначення:** один керований продуктовий портфель замість незалежних списків P0 у кожному домені.
**Межа достовірності:** інвентаризація GitHub, module manifests, документації, metadata PR та доступних GitHub Actions за exact head SHA. **Це не живий production/staging audit**, не запуск міграцій і не сертифікація бізнесових результатів. Пункти без зовнішніх доказів позначені **NOT VERIFIED**, навіть якщо код злитий до main.

## 1. Управлінське рішення: ліміт одночасної роботи

- **ACTIVE 1: Platform execution acceptance**. Зосередити увагу на наскрізному канонічному ланцюгу Goal → Plan → незалежний Approval → Platform Workflow → Domain Action → незалежний receipt → Domain outcome → Goal progress. Без нового workflow engine, паралельного Action store або безумовних повторів невизначених зовнішніх ефектів.
- **ACTIVE 2: Real Estate pilot**. Один комерційно вимірюваний use case: імпорт/внесення 10 об'єктів → перевірка дублікатів → погодження → native Property → перевірка стану → робота Sales/Real Estate з об'єктами. Насамперед PR #388.
- **MAINTENANCE: Engineering / security / operations**. Виправлення інцидентів, verification, vulnerability/production safety, CI reliability; без окремої нової продуктової хвилі. Винятки щодо стабільності дозволені.
- **GATED MAINTENANCE / R&D: Capital Markets / FOS**. Зберегти інтегрований baseline, не запускати production streaming, trading, нові пакети та великі експерименти, поки немає фінансування, operator evidence і зовнішніх acceptance conditions.
- **FROZEN: Finance, Construction, Procurement, HR та інші нові домени**, доки вони не потрібні для активного бізнес-сценарію. Growth і Documents не розширювати окремо від актуального Golden path.

**WIP limit: максимум два активних потоки, лише один із них вимагає регулярних продуктових рішень.** Не відкривати новий P0 без демонстрації блокуючої залежності для цих потоків або конкретного production incident.

## 2. Стандартизовані статуси (не плутати)

| Вісь | Значення | Що це означає |
| --- | --- | --- |
| Investment mode | ACTIVE / MAINTENANCE / FROZEN | Чи витрачаємо зараз час на новий розвиток |
| Implementation | SKELETON / IMPLEMENTED / INTEGRATED | Наскільки в коді існує потрібний шлях |
| Verification | NOT VERIFIED / CI VERIFIED / STAGING E2E VERIFIED / PRODUCTION VERIFIED | Який клас фактичних доказів є |
| Release | DRAFT / MERGED / DEPLOYED / ENABLED | Стан доставки; **MERGED не означає ENABLED** |
| Business outcome | NOT VERIFIED / EVIDENCE VERIFIED | Чи перевірено незалежний бізнесовий результат |

Джерело істини: поточний код та тести → manifest/process definitions → generated reference → narrative documentation → цей **керівний** реєстр. Реєстр не підміняє technical acceptance або runtime configuration.

## 3. Портфель потоків (рішення станом на audit)

| ID | Напрямок / межа | Mode | Спостережений baseline | Невирішене / критерій приймання | Наступний крок |
| --- | --- | --- | --- | --- | --- |
| COS-01 | Platform Workflow + Federation Goals + Actions | **ACTIVE** | #346 і #383 merged; є canonical Workflow bridge, Plan approval, saved Goal → bounded 1–3 Sales actions, outcome projections | Tenant real-data E2E, незалежне Approval і receipts, safe recovery, реальна ціль Golden 50 **NOT VERIFIED**; canonical rollout gated | Пройти один 1–3 Action scenario з реальними Deal та окремим approver |
| COS-02 | Property Bulk / Capability Library / AI-CRON intents | **ACTIVE** | #388 Draft, інтеграція коду у PR; exact-head GitHub workflows CI/Runtime/Frontend/Docs/L2 smoke success | Не merged і не deployed; реальний 10-Asset browser+Action+MySQL E2E **NOT VERIFIED**; AI/CRON тільки review intents, не autonomous execution; CSV/XLSM немає | Прийняти #388 через staging tenant і контрольний список 10 об'єктів, не обходити approval |
| COS-03 | Sales / CRM / Real Estate | **ACTIVE лише в межах COS-02** | Модулі Sales 1.0, Real Estate 1.0, Property 1.0; реалізовані CRM / matching / offer / viewing / reservation та property identity | Немає перевіреного саме цього наскрізного комерційного пілота із 10 реальними активами | Підтвердити native Property → Sales outcome і метрики часу / повторних записів |
| COS-04 | Engineering, Agents, Tool execution, Operations | **MAINTENANCE** | Engineering canonical execution/recovery активно інтегрували раніше; #353 відкритий, CI/Runtime/Frontend successful at HEAD | Відсутність production-incident доказів, щоб вважати UI polling fix вирішенням усіх STALE випадків; перевірити актуальність PR перед merge | Окремий інцидентний triage #353 і точковий fix, без нової архітектури |
| COS-05 | Capital Markets Market Data / Instrument Pool / Streaming | **GATED MAINTENANCE** | #370, #377 merged; частину #367/#374 уже forward-ported у main. Main містить canonical pool/lease/trust/streaming code | External vendor entitlements, 250 BBO + 50 L2, ≥24h real soak, failover, operator sign-off **NOT VERIFIED**. No live trading | Заморозити нову функціональність. Підготувати лише незалежну release/overlap матрицю |
| COS-06 | Capital Markets runtime Agent Team | **FROZEN R&D** | #364 Draft; exact-head CI/Runtime/Frontend/Docs success; implemented checkpoint journal and manual resume | Немає operator E2E, security approvals, autonomous production running | Залишити Draft як R&D evidence; не інтегрувати без окремого ROI-рішення |
| COS-07 | Growth / Golden 50 | **MAINTENANCE / pilot dependency** | Growth 0.50.0 має discovery → handoff → learning; disabled by default; є Golden 50 acceptance runbook | Немає незалежного доказу 50 native Growth candidates → 50 Sales Leads → 50 Documents, two-person approvals | Тестувати за потреби як acceptance COS-01, не створювати новий Growth roadmap |
| COS-08 | Platform Documents / signature requests | **MAINTENANCE** | Platform Documents runtime, Documents module 0.1.0 для governed signature requests | Запит підпису не є підписаним документом; зовнішнє підтвердження не впроваджено | Використовувати лише в Golden/business flow, не розширювати самостійно |
| COS-09 | Experience / Adaptive UX / Localization | **MAINTENANCE** | #379, #381 merged після консолідації governance і localization | Реальні UX результати, accessibility, tenant acceptance та документаційний drift потребують перевірки | Лише P0 UX дефекти активного пілота |
| COS-10 | Service / Diagnostic | **MAINTENANCE** | Manifest Service 1.0.0, Diagnostic 1.0.0; executable runtime та business lifecycle в main | Немає окремого production E2E в межах цього audit | Не розширювати без блокування active path |
| COS-11 | Finance / Construction / Procurement / HR | **FROZEN** | По 0.1.0 installable skeleton, без повного runtime/persistence | Немає бізнесового use case з вимірюваним ROI | Backlog; не оголошувати production-ready |
| COS-12 | Tokenization / додаткові вертикалі | **FROZEN** | Не є частиною підтвердженого active acceptance у цьому audit | Бізнес-модель, права на активи, legal/compliance, клієнти і бюджет | Окреме рішення лише за доведеного попиту |

Примітка: кодовий baseline із [generated module-capabilities](../12-reference/module-capabilities.md); статуси і стан release уточнюються з PR та фактичних workflows. Зелений GitHub Action без tenant E2E **не прирівнюється до production readiness**.

## 4. Незавершені GitHub PR — реєстр із рішенням

| PR | Перевірений стан 2026-10-17 | Докази | Рішення | Умова виходу |
| --- | --- | --- | --- | --- |
| [#388](https://github.com/Murakhovsky/TerraNova2026/pull/388) | OPEN, Draft; mergeable | HEAD 26f5b1ae; CI, Runtime, Frontend, Documentation, L2 Recovery Smoke success | **ACTIVE P0: acceptance, після цього окреме рішення merge** | Staging 10-Asset, duplicate check, approval, independent receipts, tenant checks, migration fresh/upgrade, operator sign-off |
| [#353](https://github.com/Murakhovsky/TerraNova2026/pull/353) | OPEN, non-Draft; mergeability false | HEAD c2b342bb; CI, Runtime, Frontend success | **MAINTENANCE: rebase/compare перед рішенням** | Довести, що UI polling fix не дублюється у main; reproduce/read-only browser regression |
| [#374](https://github.com/Murakhovsky/TerraNova2026/pull/374) | OPEN, Draft; mergeability false | HEAD 27810c90; у доступному запиті немає PR-triggered runs для цього HEAD | **HOLD / overlap audit** | Зіставити залишковий delta проти інтегрованого #377, не робити сліпий merge; external production gates окремо |
| [#367](https://github.com/Murakhovsky/TerraNova2026/pull/367) | OPEN, Draft; mergeability false | HEAD 25884fb7; CI, Runtime, Docs, Frontend, L2 Smoke, L2 Reconnect success | **HOLD / overlap audit** | Матриця #377 повідомляє 95/98 paths already identical at prior audit; встановити чи лишилося унікальне, потім закривати з provenance |
| [#364](https://github.com/Murakhovsky/TerraNova2026/pull/364) | OPEN, Draft; mergeability false | HEAD fc64c366; CI, Runtime, Frontend, Docs success | **FROZEN R&D** | Operator acceptance, real external LLM/tenant data, guarded resume, clear ROI before promotion |

**Важливо:** #377 уже merged, хоча його історичний текст PR описує DRAFT / NO MERGE як стан на момент створення. Стан GitHub є джерелом правди для merge; однак немає доказу live-market certification. Також #383 merged, але його PR-текст не стверджував local Runtime/MySQL PASS та staging acceptance. Не називати ці сценарії production-complete.

## 5. Відкриті Issues

| Issue | Mode | Рішення |
| --- | --- | --- |
| [#360](https://github.com/Murakhovsky/TerraNova2026/issues/360) Production Streaming | GATED MAINTENANCE | Залишити як acceptance umbrella без нової реалізації, поки немає ресурсів і real provider proof; синхронізувати із #377/#374 |
| [#386](https://github.com/Murakhovsky/TerraNova2026/issues/386) H1/H2 scheduled scans | FROZEN | Unique registry залишився невиконаним після закриття #318; не повертати паралельний scanner/Workflow. Реактивація лише після пріоритетного рішення |

## 6. Реальна готовність: три незалежні перевірки

1. **Code & CI:** PR, HEAD SHA, набір необхідних checks, тестовий датасет, конкретні міграції та рев'ю.
2. **Staging operational acceptance:** потрібний tenant, модульний READY (version/schema/dependencies), feature flags, два реальні різні актори для Approval, worker/outbox, CLI/API, браузер, перевірка через native read, failure/recovery.
3. **Production/business acceptance:** deployed SHA + applied schema + enabled tenant, безпечний rollback, достовірні external receipts, перевірений реальний результат. Для Capital Markets ще окремо ліцензії, issuer rights, live soak та нульова дозвільна влада live orders за замовчуванням.

Audit **не запускав** full verify, MySQL migrations, staging browser, реальний 10-item бізнес-кейс, production observability чи операторську перевірку. Класифікація "CI VERIFIED" базується на переглянутих GitHub Actions відповідних commit SHA, не на локальному повторі тестів.

## 7. Обмежений порядок робіт (найближчий цикл)

| Seq | Result-oriented work item | Depends on | Acceptance | Priority |
| --- | --- | --- | --- | --- |
| 1 | Завершити small Goal → canonical Workflow → 1–3 Sales Actions → verified outcome | #346 + #383 в main, active tenant, approval policy | Реальні Deal IDs, різні manager/approver, незалежний Action receipt, outcome, retry-denial, no dual write | P0 |
| 2 | Довести #388 до E2E 10-Asset в Property | COS-01, staging tenant, migrations | 10 ізольованих records; NEW/MERGE/REVIEW cases; human approval; exact count in native Property; no duplicate writes; safe replay | P0 |
| 3 | Перевірити комерційну користь у Real Estate | 1–2 | Як мінімум один бізнес-користувач, зафіксовані час внесення, дублікати, зусилля оператора, follow-up/CRM actions | P0 |
| 4 | Опрацювати #353 як обмежений maintenance fix | Reproduce on current main | Browser regression, відсутність dub/frozen polling, не міняє actual workflow authority | P1 safety |
| 5 | Звірити #367/#374 і #364 з main, зберегти унікальне | #377, git diff/patch | Per-path provenance; no duplicate schema/provider writer; security/release gates untouched | P1 hygiene |
| 6 | Привести product current-scope/domain-map до фактичних manifest і latest main | 1–2 визначили acceptance | Немає суперечностей "злито / працює / увімкнено / прийнято" | P1 docs |

**Не додавати задачі лише тому, що вони цікаві, а їхні тести зелені.** Нові ідеї записуються в Backlog, доки не звільниться один із двох ACTIVE slots.

## 8. Що вважати закриттям циклу

- У портфелі у кожного напряму є один mode, один next decision, один owner/authority, один доказовий gate.
- Є лише два ACTIVE потоки і одна спільна пріоритизація, а не десятки доменних P0.
- Усі 5 відкритих PR та 2 Issues мають явно визначений статус і рішення (розробка, приймання, hold або frozen).
- COS-01 і COS-02 перейшли із "код/CI" у **staging E2E verified** із реальними persisted receipts; до цього не позначати "Done".
- Dashboard/report має відрізняти **deployed/ready/enabled** від **verified business outcome**.
- Перегляд Portfolio раз на тиждень: перегляд evidence + фінансового ефекту + рішення ACTIVE/HOLD/FROZEN. Будь-який "critical P0" повинен називати блоковану active Goal, не просто severity.

## 9. Документальний drift і відповідальність

- [docs/01-product/current-scope.md](./current-scope.md) останній раз позначений 2026-10-06 і не відображає всіх нових інтеграцій 2026-10-16.
- [docs/03-architecture/domain-map.md](../03-architecture/domain-map.md) позначений 2026-09-24 і має застарілі версії деяких модулів, порівняно з [generated capabilities](../12-reference/module-capabilities.md).
- **Owner стратегічних ACTIVE/FROZEN рішень:** власник COS. **Technical evidence:** Engineering/QA workflows і Domain owners. **Release authority:** окремі операторські approvals/guards; документ не дає дозволу на деплой або фінансові операції.
- Джерела для engineering: [Federation canonical execution](../03-architecture/federation-goal-canonical-execution.md), [Golden 50 acceptance](../02-workflows/federation-golden-50-operator-acceptance.md), [Capital Markets integration matrix](../04-domains/capital_markets/cos-cm-integration-matrix-20261016.md), [module readiness](../10-operations/module-readiness.md).

**Оновлюйте цей Portfolio при зміні режиму/пріоритету.** Більш дрібні task lists можуть існувати в Issues/Goals, але не мають власної незалежної "стратегічної" шкали P0.
