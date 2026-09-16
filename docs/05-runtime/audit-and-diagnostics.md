---
title: Аудит і діагностика виконання
description: Пояснюваність runtime, трасування, стан системи та межі технічної діагностики COS.
status: active
updated: 2026-09-16
kind: runtime
---

# Аудит і діагностика виконання

COS повинен не лише виконати дію, а й пояснити **чому вона відбулася** і де зламався процес, якщо вона не відбулася.

## Audit, Logs і Metrics

Це три різні речі.

### Аудит

Audit (аудит) є пояснюваним слідом бізнесового й технічного виконання:

- Event;
- decision;
- Action;
- Policy;
- Approval;
- execution result;
- actor або Agent;
- correlation.

`Kernel/Audit/AuditEntry` є загальним представленням, а зберігання реалізує Infrastructure.

### Журнали

Logs (журнали) містять технічні деталі виконання: stack trace, помилки провайдера, повідомлення worker та іншу діагностичну інформацію.

### Метрики

Metrics (метрики) є агрегованими числовими сигналами: latency, throughput, retry rate, denied actions, approval time, queue depth, LLM failures.

Не варто будувати аудит за допомогою `grep` по logs. Це швидкий спосіб перетворити incident response на квест без призу.

## Модель кореляції

Наскрізна операція повинна відновлюватися через correlation і causation identifiers:

```text
Business Event
   ↓ correlation
Rule / Agent run
   ↓
ActionProposal
   ↓
PolicyEvaluation
   ↓
Approval
   ↓
Queue Job
   ↓
Action execution
   ↓
Result Event
```

## Що має бути пояснюваним

Для Action система повинна дозволяти відповісти:

1. хто або що її ініціювало;
2. яка Event була причиною;
3. який Rule або Agent створив proposal;
4. який context було використано або на який context є посилання;
5. яка Policy спрацювала;
6. чому рішення було `AUTO`, `APPROVAL_REQUIRED` або `DENIED`;
7. хто погодив дію;
8. який handler її виконував;
9. який зовнішній adapter було використано;
10. який `ExecutionResult` отримано.

## Діагностика Agent

Діагностика LLM не повинна безконтрольно зберігати raw sensitive context.

Поточна модель передбачає:

- `SensitiveContextRedactor`;
- перевірку структурованого результату;
- записи виконання Agent;
- правила retention/removal для чутливих вхідних даних;
- відокремлення prompt/context від mutation executor.

Корисні метрики Agent:

- частка валідних структурованих відповідей;
- частка відхилених пропозицій;
- частка `DENIED` від Policy;
- частка людських погоджень;
- успішність виконання після погодження;
- затримка та вартість LLM;
- повторні й повторно запущені виконання.

## Діагностика Queue

Operations мають показувати:

- ready jobs;
- leased jobs;
- retries;
- dead letters;
- вік найстарішого очікуваного job;
- heartbeat worker;
- розподіл причин помилок.

## Діагностика модулів

Для кожного Domain module потрібно бачити:

- чи його виявлено;
- чи валідний manifest;
- чи сумісний він із Kernel;
- чи встановлений;
- чи активний для organization;
- чи зареєстровані contributions;
- чи є конфлікти ownership;
- чи присутня необхідна configuration.

## Diagnostic Domain і технічна діагностика

Окремий бізнесовий Domain `Diagnostic` не треба плутати з технічною діагностикою Kernel.

- **Kernel diagnostics**: здоров’я runtime та Infrastructure.
- **Business diagnostics**: оцінювання процесів компанії, scoring, findings і recommendations.

Для бізнесової моделі використовуйте канонічні сторінки [Diagnostic Domain](../04-domains/diagnostic/overview.md).

## Порядок читання інциденту

```text
1. Health / worker state
2. Event/outbox status
3. Consumer checkpoint
4. Agent/rule decision
5. Policy evaluation
6. Approval state
7. Queue job
8. Action result
9. Integration adapter logs
```

Не починайте з перезапуску worker. Restart без розуміння стану іноді лише швидше повторює ту саму помилку.

## Інваріант

> Будь-яка автономна дія COS має бути не лише виконуваною, а й прив’язаною до джерела, відтворюваною по трасі та пояснюваною.
