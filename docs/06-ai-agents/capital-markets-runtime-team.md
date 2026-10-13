---
title: Runtime Agent Team ринків капіталу
description: Наскрізний керований цикл Capital Markets від спостереження до вимірювання та навчання без автономної живої торгівлі.
status: draft
kind: engineering
updated: 2026-10-13
---

# Команда агентів ринків капіталу

Мета: об'єднати сім окремих зареєстрованих агентів через реальний `Kernel\\Agent\\Service\\AgentRuntime` і доменні джерела достовірних даних, а не через набір декоративних назв.

## Склад команди та workflow

| Етап | Агент / сервіс | Повноваження |
| --- | --- | --- |
| Observe | Market Intelligence Agent | Визначає стан джерел, свіжість і обмеження ринкових даних |
| Research | Research Agent | Дослідження гіпотез через чинні контрольовані інструменти |
| Detect | Opportunity Agent | Аналізує збережені можливості, не вигадує spread |
| Assess | Risk Agent | Тлумачить детермінований Risk Envelope, не змінює ліміти |
| Allocate | Portfolio Agent | Аналіз та пропозиції на основі існуючих engine і tool runtime |
| Execute | Execution Agent | Перевіряє передумови paper execution; **не виставляє ордери** |
| Measure | Capital Markets Orchestrator Agent | Аналізує вже фіналізовані виконання та результат |
| Learn | Research Agent | Фіксує висновки, не здійснює автоматичного promotion |

Окремий `CapitalMarketsRuntimeOrchestrator` є детермінованим coordinator, а не заміною фінансових engine. Кожен виклик LLM створює стандартний `AgentRun` з `correlation_id`. До наступного етапу передаються тільки обмежені витяги результатів попередніх етапів, а не неструктуровані інструкції для виконання.

## Обов'язкові обмеження

1. Модуль Capital Markets повинен бути активний саме для організації, яка здійснює виклик.
2. Після Observe мають існувати зареєстровані джерела й хоча б один справді `TRUSTED` / `LIVE` MarketState або ReferenceMarketState. Delayed і stale дані не відкривають шлях до угод.
3. Detect проходить далі тільки з уже збереженою й не відхиленою Opportunity за потрібним ID.
4. Assess / Allocate вимагають наявних Risk Snapshot, Risk Envelope, Capital State та Allocation Plan від детермінованої системи. До розгляду виконання допускаються лише Opportunity зі статусом APPROVED/RESERVED/EXECUTING/EXECUTED та збереженим позитивним RiskDecision (APPROVE або APPROVE_WITH_LIMIT); для нових ордерів також перевіряється expires_at.
5. Навіть після позитивної рекомендації `Execution Agent` виконання не створюється: процес повертає `AWAITING_EXECUTION_EVIDENCE`. Для переходу на Measure потрібен уже збережений **фіналізований paper execution** цього Opportunity.
6. Будь-яке `BLOCKED` від агента також зупиняє цикл. Агентська рекомендація ніколи не може скасувати рішення детермінованого gate.
7. Кожен новий агент має `allowedActionTypes=[]`, `maxActionsPerRun=0`, `configurationManaged=false` і validator. Tool request-и для нових агентів заборонені. Research/Portfolio зберігають попередню керовану двопрохідну логіку.

Це **операційний агентний цикл із ручним виконанням**, а не unattended order execution. Оркестратор не має API для live trading, не змінює Ledger і не затверджує пропозицій капіталу.

## Тестовий виклик оператором

За замовчуванням ручна CLI-команда вимкнена. Після перевірки tenant, конфігурації LLM, секретів і тестових даних:

```bash
COS_CM_RUNTIME_AGENT_TEAM=1 php symfony/bin/console \
  cos:capital-markets:agents:cycle ORG_ID OPPORTUNITY_ID \
  --question="Оціни можливість, ризики, розподіл капіталу і результати"
```

Повертається `status`, `correlation_id`, `stage_count`, послідовність `stages[]`, `run_id` кожного виконаного агента й `next_action`. Позитивний вихід CLI не означає схвалення угоди. Для `BLOCKED` команда завершується з помилкою.

## Збереження циклу та безпечне відновлення

Кожен запуск із `correlation_id` зберігається в `tn_capital_market_agent_cycles`. Таблиця створюється Doctrine-міграцією `Version20261013211500`. Ідентифікатор унікальний у межах організації. Разом із ним фіксуються opportunity, хеш початкового запитання, власник запуску, статус і контрольні точки агентів. Змінити opportunity або питання зі старим correlation неможливо.

Checkpoint записується в MySQL **відразу після успішного AgentRun**, включно з його `run_id`. При повторному запуску завершений статус повертається із журналу без нових LLM-викликів. Поки інший процес `RUNNING`, повторний виклик одержує `IN_PROGRESS`. Перерваний цикл відновлюється лише явно:

```bash
COS_CM_RUNTIME_AGENT_TEAM=1 php symfony/bin/console cos:capital-markets:agents:cycle ORG_ID OPPORTUNITY_ID \
  --correlation=ПОПЕРЕДНІЙ_CORRELATION_ID --resume \
  --question="Ідентичне питання, як у першому запуску"
```

Для `AWAITING_EXECUTION_EVIDENCE` та `FAILED` можна виконувати `--resume` після людської перевірки. Для `RUNNING` аварійне відновлення доступне лише після щонайменше 30 хвилин без нового checkpoint і тільки з явним `--resume`. Кожен старий власник відхиляється власним owner-token CAS. Перевірки MarketState, Risk Envelope та approval **виконуються знову** під час resume, навіть якщо LLM-етап було checkpointed.

Увага: якщо LLM або дозволений Research Tool завершився безпосередньо перед записом checkpoint і процес аварійно зник, наступний запуск може повторити його. Тому це гарантія **at-least-once із захистом журналу, не exactly-once side effects**. Автоматичний таймер recovery не вмикаємо до окремої перевірки.

## Що ще необхідно для production-повноти

Цей пакет реалізує реєстрацію агентів, ізольовані контексти, LLM-виклики, валідацію, safety gates, ручний координатор і тести. До повноцінної автономної команди ще потрібні: розширена state-machine з автоматичним retry/heartbeat, API/UI контролю кожного рішення, асинхронна черга та retry, policy-based human approval, реальне підключення контрольованого paper execution після зовнішнього approval, фінансова атрибуція з повним циклом вимірювання, end-to-end тест із реальною БД і LLM та production observability. Live trading заборонений до окремого приймання.
