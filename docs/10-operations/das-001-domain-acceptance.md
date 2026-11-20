---
title: DAS-001 — Domain D3–D5 acceptance runbook
description: Незалежне приймання installable Domains без підміни доказів деклараціями.
status: active
updated: 2026-11-20
kind: operations
---

# DAS-001: незалежне приймання Domain

Цей runbook не надає доступу й не запускає side effects. Мітка \`UNKNOWN\` не є дефектом: це чесна відсутність незалежних доказів.

## D3 — наскрізне приймання на тестовому середовищі
1. Закріпити exact commit SHA, staging environment, tenant і scenario. Виконавець тесту не може самостійно схвалювати фінансові або зовнішні Action.
2. Перевірити ModuleLifecycle для tenant, permissions, versioned capability, schema validation, approved execution origin, actual handler і audit correlation.
3. Пройти UI/API → Capability → Use Case → durable Workflow/Action (де застосовно) → native Domain persistence → незалежний readback.
4. Негативні сценарії: cross-tenant, відсутній дозвіл/Approval, duplicate idempotency key, partial external effect, quarantine і recovery.
5. Незалежний reviewer фіксує performed_at, PASS/FAIL, native rows/result refs та URL до журналу виконання. Лише після цього оновлюється \`readiness.evidence\`.

## D4 — користувацьке приймання
Той самий exact SHA/environment/tenant + сценарій реального користувача у відповідному Workspace, user signoff, контроль заборонених функцій та відтворювані результати. Перевірити результати для кожного зовнішнього provider окремо.

## D5 — підтверджена робота у виробничому середовищі
Виконати контрольований canary у production на погодженому tenant зі справжнім native readback, operational alerting та перевіреною процедурою rollback або безпечного disable. CI green, \`enabled_by_default\`, UUID чи успішний Action status самі по собі не є D5.

## Запис evidence у Domain Passport

Для D3–D5 кожен \`readiness.evidence[]\` повинен містити \`scenario\`, \`revision_sha\` (40 hex), \`environment\`, \`tenant\`, \`performed_at\` (ISO з timezone), \`result: PASS\`, конкретний \`business_readback\`, \`evidence_url\` (HTTPS artifact) і \`independent_reviewer\`.

Evidence не повинен містити access token, секрети, персональні дані клієнтів чи платіжні credentials. На момент створення цього runbook усі паспорти main лишаються \`UNKNOWN\`, а staging acceptance не проводився.

## Функціональні сценарії на першочергове приймання

| Domain | Критичний наскрізний сценарій |
| --- | --- |
| Property | Tenant create/update/search + bulk 10 assets, Approval, native asset readback |
| Sales | Lead intake → Case → owner → follow-up → outcome; незалежний Sales readback |
| Real Estate | Opportunity → matching → viewing → reservation з Property references |
| Diagnostic | Session → methodology → evidence → evaluation → recommendation |
| Service | Request → Ticket → Assignment/SLA → Resolution → Close |

Невиконаний сценарій зберігає \`UNKNOWN\` або \`FAILED\`. Жоден pipeline не підвищує readiness автоматично.
