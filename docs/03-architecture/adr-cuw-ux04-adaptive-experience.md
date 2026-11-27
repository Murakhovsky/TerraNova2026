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
- **Reuse:** FederationExperiencePreferenceStore та наявні таблиці з tenant/user scope. Загальний режим діє на всі пілотні Unified-екрани; розкриті секції належать Workspace і позитивно підтвердженому EntityRef або пакету. Legacy Federation Workspace overrides збережено. Міграція не потрібна. Недоступні або пошкоджені налаштування дають безпечний Result.
- **New adapter:** AdaptivePagePresentation — SSR-адаптер канонічного resolver і наявного preference store, а не альтернативний рушій. POST на тому самому екрані перевіряє CSRF та вузький allowlist mode/section і повертає до перевіреного внутрішнього маршруту. GET читає native state. Браузер не задає trigger, capability, tenant, owner або redirect authority. POST не викликає Action або Plan mutation.
- **Спільні Twig-контроли:** одна модель сторінки замість трьох UI. Desktop/mobile використовують responsive CSS, доступні кнопки і нативні серверні форми; без залежності від AI або JS. Критичні погодження, ризики, помилки, неактуальні чи невідомі результати та недоступність залишаються видимими. Тригерну секцію не можна згорнути. Expert показує додаткові ідентифікатори з авторизованого читання, не довільні логи чи raw payloads.

## Межі та приймання

| Пілот | Result | Process | Expert |
|---|---|---|---|
| R1 Home та Property/Sales Workspace | Той самий пошук, summary, канонічне посилання | Активність, пов'язані сутності, додаткові дії | Підтверджені ідентифікатори та correlation ID видимої активності |
| R2 Property Query і людська форма | Той самий typed read і дозволена форма | Причини доступності операцій | Contract ID/status/risk та EntityRef |
| R3 Property bulk | Native per-item progress, невідомий outcome, перевірка й обов'язкові погодження | Lifecycle status і стан кожної позиції | Ті самі Batch/Goal/Plan ID та digest; явне розкриття JSON input |

Lifecycle активності — статус провайдера, а не доказ бізнес-успіху. Стан planned пакета не доводить фактичне очікування чи отримання approval: попередження описує обов'язковий gate і веде до канонічного Goals без вигаданого стану погоджувача. Verified native item count не є фінальним Goal Outcome.

Тести: unit matrix усіх десяти сигналів, правил розкриття, capability fence, mode/action parity, видимості рішень і дедуплікації. Існуючий ізольований R1/R2/R3 browser suite розширено збереженням mode/section, CSRF/forged input/role негативами, незмінністю EntityRef і progress, відсутністю нових Action/Run/native writes від preference changes, screen evidence. PASS потребує фактичного CI на SHA PR.

## Відкат і залишкові межі

Відкат adapter/routes/templates без міграції даних; персистентні UI preferences можуть лишатися невикористаними. Доменні зміни й eligibility залишаються канонічними. Повний UX-04 acceptance залежить від реального browser/runtime evidence, cross-device/tenant, accessibility та наявного R3 worker/receipt gate. Production enablement не здійснювався. UX-05 operation aggregation та UX-07 Goal composition — окремі пакети.
