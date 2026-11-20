---
title: Приймання COS Unified Workspace UX-00…UX-03
description: Зріз реалізації, фактичні свідчення і заборонені передчасні висновки щодо чотирьох UX-етапів.
status: preacceptance
updated: 2026-11-20
kind: implementation
---

# Приймання Unified Workspace UX-00…UX-03

## Межа пакета

Цей документ фіксує чотири етапи Master Spec v1.0, §19: UX-00 аудит і контрольна база; UX-01 Shell; UX-02 типізовані запити та дії; UX-03 бізнесові робочі простори. UX-04…UX-09 не є частиною цього релізу.

Усі UX PR виконані у Draft гілках. Статус CONTRACTED у Page Registry означає лише наявність контракту, а не production QA чи виконаної бізнес-операції.

## Фактичні свідчення

| Етап | Кодова складова | CI | Приймання |
|---|---|---|---|
| UX-00 | [PR #432](https://github.com/Murakhovsky/TerraNova2026/pull/432) | [Runtime 44710821100](https://github.com/Murakhovsky/TerraNova2026/actions/runs/44710821100): CI, Runtime, Frontend, Documentation, L2 PASS; Property 24/24 на ізольованих даних | **Частково:** staging S0–S5, незалежний Property Action та виконаний Run UI, оператор не підтвердили |
| UX-01 | [PR #446](https://github.com/Murakhovsky/TerraNova2026/pull/446), канонічний Shell і tenant-scoped пошук | CI/Documentation/Frontend/L2 були зелені на послідовному SHA; повний Runtime та acceptance верифікуються окремо | **Не підписано:** desktop/mobile/dark/locale/keyboard/performance |
| UX-02 | [PR #447](https://github.com/Murakhovsky/TerraNova2026/pull/447), Query/Action і безпечний allowlist presentation | CI/Documentation/Frontend/L2 були зелені; Runtime та E2E верифікуються окремо | **Не підписано:** повний R1/R2 + identity/receipt |
| UX-03 | [PR #448](https://github.com/Murakhovsky/TerraNova2026/pull/448), Sales/Property Workspaces, read-only EntityRef, activity, session saved filter views | Тести мобільності/безпеки додано, результат на поточному SHA не успадковується з попереднього | **Не підписано:** UX-03 pilot, живі дані, ручна зручність |

## Обов'язкова матриця оцінювання

1. **Середовище:** зафіксовані source SHA, tenant, module manifest і доступний runtime, Symfony DI/router, версія БД, dry-run міграцій.
2. **R1:** Sales/Property пошук на справжніх тестових записах, деталізація, порожній/негативний фільтр, контроль іншого tenant та ролі, desktop і mobile; без SQL write.
3. **R2:** людська форма, CSRF, авторизація, стабільний idempotency key, рівно один native Property запис, посилання й повторне Domain readback.
4. **R3:** CSV stage → preview → Plan → незалежні Plan та Action approvals → канонічний Workflow/Worker → verified per-item receipts → кінцевий Goal outcome у браузері. Відсутність допуску worker є **BLOCKED**, а не PASS.
5. **UX-01:** одна система навігації, функціональний Shell, світла/темна тема, локалізація, клавіатура, доступність, справжня індикація AI/realtime, що не приписує неперевіреного Live.
6. **UX-02:** усі exposed дії мають типізований контракт, доступність через permission/Policy, версію, статус і safe human result. Unknown async не може означати success.
7. **UX-03:** дві незалежні provider-owned сутності (Sales, Property), bounded list/card, серверний пошук, позитивний EntityRef proof, негативний чужий EntityRef, контекст/активність, mobile без overflow та нуль несанкціонованих мутацій.
8. **Надійність:** збої read provider, відсутній AI і offline/reconnect ніколи не створюють записів чи вигаданих результатів.
9. **Випуск:** незалежний оператор перевіряє CI на тому самому коді, вплив на існуючі URL, rollout/rollback, безпеку tenant і письмово дозволяє злиття. До цього всі Draft PR зберігаються без merge.

## Підтверджені архітектурні межі

- Єдиним джерелом команд та запитів лишаються поточні canonical Domain/Application/Capability Gateway/Policy/Workflow.
- Shell читає тільки active WebExtension Providers, жодних копій доменних таблиць.
- UI saved filter views зберігаються **в серверній сесії** користувача й організації: це не довготривала синхронізація між пристроями.
- Відсутнє автоматичне permission escalation, активація external Action Worker чи постинг Meta/Shopify.
- Якщо даних/підтвердження немає, UI повідомляє про недоступність або очікування, не про виконання.
- Ланцюг випуску: #432 → #446 → #447 → #448. Усе інше відкладено.

## Поточні незакриті обмеження

Технічні Draft PR не є сертифікацією production, operator acceptance чи показником завершення Golden 50. Staging gate [#431](https://github.com/Murakhovsky/TerraNova2026/issues/431) і контрольний [#428](https://github.com/Murakhovsky/TerraNova2026/issues/428) залишаються відкритими. Не змінювати production default `FailClosedWorkflowActionWorkerAdmission` заради тестового PASS.

## A11y, швидкість і локалізація

В останньому UX-03 Playwright-доказі скануємо всі чотири реальні авторизовані Unified pages на телефонному viewport (390×844) через Axe WCAG 2.x A/AA. Блокуємо serious/critical violations, записуємо навігаційний час і вимагаємо його нижче 15 секунд у ізольованому CI (це guard, не фінальне SLA). Unit source contract перевіряє кожен використаний у Twig `uw.*` ключ в обох каталогах. Оцінку реальної продуктивності та фокус на iOS/Android потрібно повторити під час staging acceptance.
