---
title: ADR — UX-04 Адаптивний досвід
description: Єдиний авторизований бізнес-стан, детерміноване розкриття деталей і персональні налаштування.
status: isolated-pilot
updated: 2026-11-27
kind: architecture
---

# UX-04 — Адаптивний досвід

## Повторне використання, адаптація та нові компоненти

- **Reuse:** ті самі Application Query/Command, UIActionResolver, Workspace provider, Property Bulk Intake та Federation Plan/Workflow; не додаємо бізнесового сховища чи шляху виконання.
- **Adapt:** адитивно розширюємо ExperienceContext і ExperienceSemantic фіксованими довіреними сигналами та серверним контекстом Workspace, сутності, операції й surface. Збережено сумісність масивів секцій, схеми v1, фільтрації actions і серіалізації. Resolver перевіряє доступність capability перед розкриттям, потім critical/always/contextual signals, явне розкриття та mode default. Дозволені рішення лишаються первинними, дублікати й недозволені mutations не стають контролами.
- **Reuse:** FederationExperiencePreferenceStore та наявні таблиці з tenant/user scope. Загальний режим діє на всі пілотні Unified-екрани; розкриті секції належать Workspace і позитивно підтвердженому EntityRef або пакету. Legacy Federation Workspace overrides збережено. Міграція не потрібна. ExperienceState нормалізує збережені значення. Під блокуванням профілю змінюється лише вибрана секція, повторення запиту не змінює її версію, а паралельні зміни інших секцій не втрачаються. Недоступні або пошкоджені налаштування дають безпечний Result: критичні сигнали та дозволена legacy JSON-форма bulk залишаються доступними.
- **New adapter:** AdaptivePagePresentation — SSR-адаптер канонічного resolver і наявного preference store, а не альтернативний рушій. Окремий файл маршрутів `config/routes/cuw_adaptive.yaml` реєструє лише POST на тих самих шляхах, без правок спільного `routes.yaml`. POST на тому самому екрані перевіряє CSRF та вузький allowlist mode/section і повертає до перевіреного внутрішнього маршруту. GET читає native state. Браузер не задає trigger, capability, tenant, owner або redirect authority. POST не викликає Action або Plan mutation.
- **Спільні Twig-контроли:** одна модель сторінки замість трьох UI. Desktop/mobile використовують окремий `styles/cuw-adaptive.css` та домен локалізації `cuw_adaptive` (UK/EN), без змін спільних стилів і каталогів, доступні кнопки й нативні серверні форми з поверненням фокуса до контрольного елемента через локальний fragment; без залежності від AI або JS. Критичні погодження, ризики, помилки, неактуальні чи невідомі результати та недоступність залишаються видимими. Тригерну секцію не можна згорнути. Expert показує додаткові ідентифікатори з авторизованого читання, не довільні логи чи raw payloads.

## Межі та приймання

| Пілот | Result | Process | Expert |
|---|---|---|---|
| R1 Home та Property/Sales Workspace | Той самий пошук, summary, канонічне посилання | Активність, пов'язані сутності, додаткові дії | Підтверджені ідентифікатори та correlation ID видимої активності |
| R2 Property Query і людська форма | Той самий typed read і дозволена форма | Причини доступності операцій | Contract ID/status/risk та EntityRef |
| R3 Property bulk | Native per-item progress, невідомий outcome, перевірка й обов'язкові погодження | Lifecycle status і стан кожної позиції | Ті самі Batch/Goal/Plan ID та digest; явне розкриття JSON input |

Lifecycle активності — статус провайдера, а не доказ бізнес-успіху. Стан planned пакета не доводить фактичне очікування чи отримання approval: попередження описує обов'язковий gate і веде до канонічного Goals без вигаданого стану погоджувача. Verified native item count не є фінальним Goal Outcome.

Тести: unit matrix усіх десяти сигналів, правил розкриття, capability fence, mode/action parity, видимості рішень і дедуплікації. Існуючий ізольований R1/R2/R3 browser suite розширено збереженням mode/section, CSRF/forged input/role негативами, незмінністю EntityRef і progress, відсутністю нових Action/Run/native writes від preference changes, screen evidence. Додано перевірку клавіатурного керування Enter/Space, повернення фокуса, ARIA стану та ізоляції налаштувань при однакових actor ID у різних tenants. Додано SSR-компонентне приймання двох незалежно налаштованих організацій з реальними обліковими записами та членством у MySQL, перевіркою невдалого міжтенантного входу, пошкоджених налаштувань і окремого недоступного з'єднання з preference store. Усі записи компонентного тесту відкочуються. Browser acceptance додатково перевіряє дві незалежні автентифіковані сесії того самого reviewer: desktop ↔ mobile, mode/section, авторизований EntityRef, no-write та завантаження виділених ресурсів локалізації. PASS потребує фактичного CI на SHA PR.

### Підтвердження попередніх пакетів

На SHA `a9de71df8b8eb940eeeda5e0f498e92b24463198` PR #527 успішні всі п'ять перевірок: [CI](https://github.com/Murakhovsky/TerraNova2026/actions/runs/46024767029), [Runtime](https://github.com/Murakhovsky/TerraNova2026/actions/runs/46024766866), [документація](https://github.com/Murakhovsky/TerraNova2026/actions/runs/46024766845), [frontend](https://github.com/Murakhovsky/TerraNova2026/actions/runs/46024766912), [приймання Product CSV](https://github.com/Murakhovsky/TerraNova2026/actions/runs/46024767092). R1/R2/R3 browser acceptance і артефакт скриншотів успішні на цьому SHA.

На SHA `f4b43cd3bc3f706c617d09ab77f7b023aeb1ef91` успішні [CI](https://github.com/Murakhovsky/TerraNova2026/actions/runs/46029584686), [Runtime](https://github.com/Murakhovsky/TerraNova2026/actions/runs/46029584687), [Frontend](https://github.com/Murakhovsky/TerraNova2026/actions/runs/46029584691), [документація](https://github.com/Murakhovsky/TerraNova2026/actions/runs/46029584663) та [браузерні докази](https://github.com/Murakhovsky/TerraNova2026/actions/runs/46029584687/artifacts/15214920200). Перевірки третього пакета потребують повторного запуску.

## Відкат і залишкові межі

Відкат adapter/routes/templates без міграції даних; персистентні UI preferences можуть лишатися невикористаними. Доменні зміни й eligibility залишаються канонічними. Ізольоване приймання UX-04 потребує exact-SHA browser/runtime evidence. Окремий хост для другої організації не розгортався: компонентні тести викликають чинний сервіс автентифікації з різним налаштуванням організації, але не заявляють повний HTTP-вхід двох організацій через один хост. Об’єднане приймання A–F та R3 worker/receipt gate залишаються окремими. Production enablement не здійснювався. UX-05 operation aggregation та UX-07 Goal composition — окремі пакети.
