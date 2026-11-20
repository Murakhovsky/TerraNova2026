---
title: Контроль маркетингових бюджетів у Finance F1
description: Архітектурне рішення щодо власника фінансових лімітів, резервувань, журналу доказів і запобігання подвійним витратам.
status: draft
updated: 2026-11-19
kind: decision
---

# FIN-ADR-001: Finance володіє бюджетом, а не рекламною кампанією

Це рішення визначає перший фінансовий контур для майбутніх операцій Marketing Paid Media.

1. Finance є єдиним власником Budget Envelope та Reservation. Marketing не веде власного залишку чи фінансового леджера й не читає приватні таблиці Finance.
2. Кожен конверт має `organization_id`, валюту, календарний період, обмежений `limit_minor` і окреме джерело маркетингового плану. Дрібні валютні одиниці є цілими числами; розрахунків через `float` немає.
3. Конверт починається як `DRAFT`. Активація, резервування, звільнення резерву і замороження можливі лише після окремого Action/Policy/Approval. Жоден AI-агент не є фінансовим writer.
4. SQL-рядок конверта блокується перед кожною зміною фінансового залишку. Оптимістична ревізія, надійний ідемпотентний receipt і `organization_id` захищають від повторів і перегонів.
5. Резервування не є оплатою, списанням з банку або підтвердженою рекламою. `Meta spend` є спостереженням, доки Finance settlement не отримав незалежний перевірений доказ.
6. Freeze забороняє нові резерви, але не може магічно зупинити кампанії, уже активовані поза COS. Існуючі резерви залишаються, поки їх не звільнять погодженою дією.
7. Новий Domain Action не отримує bypass через UI/AI/API/CRON. Джерелом виконання є Kernel/Platform Workflow. Наявність capability не дорівнює праву її викликати.
8. F1 залишається disabled by default. Немає ані Meta платного запису, ані settlement/Expense, ані доказу фактичних коштів на банківському рахунку.

## FIN-ADR-001a: Відокремлена рекламна observation

9. Meta/Marketing reported spend не резервує й не списує кошти. Finance зберігає source-reported decimal, source snapshot ID/version/hash, reporting window, timezone, currency, attribution та tenant в окремому незмінному журналі ревізій.
10. Evidence залишається `UNVERIFIED_SOURCE`, доки повноцінний перевірений Marketing→Finance bridge не підтвердив походження. Фінансове `reconciliation.status=UNRECONCILED` навіть якщо Meta позначив джерело `FINALIZED_BY_SOURCE`.
11. Без invoice/payment/settlement receipt не створювати `Expense`, не оновлювати `committed_minor` і не показувати net profit.
12. Повторні імпорти мають бути ідемпотентними. Різні reporting windows, attribution settings і валюти не можна бездумно сумувати. F1 Query використовує тільки точне й однорідне порівняння.
