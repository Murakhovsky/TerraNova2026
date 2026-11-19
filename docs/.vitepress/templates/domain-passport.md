---
title: <Назва Domain> — паспорт COS
description: Опис відповідальності, компонентів, контрактів, виконання та доказів готовності домену COS.
status: draft
updated: 2026-11-19
kind: domain
contract: domain-v1
domain_id: <id>
---

# Паспорт домену <Назва>

<!-- COS-DOMAIN-PASSPORT-DATA
{
  "schema_version": 1,
  "domain_id": "<id>",
  "manifest": "app/Domains/<Name>/module.php",
  "components": [
    { "id": "component_id", "capability_ids": [] }
  ],
  "process_ids": [],
  "readiness": { "level": "UNKNOWN", "evidence": [] }
}
COS-DOMAIN-PASSPORT-DATA -->

## 1. Ідентичність
Власник, призначення, business outcome та користувачі. Version/kernel/schema читаються з manifest.

## 2. Межі відповідальності
Чим володіє і чим принципово не володіє Domain. Authoritative business facts та інваріанти.

## 3. Внутрішні компоненти
| component_id | Бізнесова функція | Власний стан | Залежності | Capabilities |
| --- | --- | --- | --- | --- |
| component_id | ... | ... | ... | ID з manifest |

## 4. Бізнесова модель
Aggregate roots, transitions, invariants, events, persistence та migrations.

## 5. Use Cases та Capabilities
Бізнесовий намір → typed capability → Application use case → перевірений результат; `module.php` є джерелом ID.

## 6. Виконання
UI/API/AI/CRON, Policy, Approval, canonical Workflow/Action, errors, idempotency та recovery.

## 7. Міждоменні контракти
Consumer/provider authority, `requires/provides`, types, auth, tenant і failure semantics.

## 8. Процеси та результати
Точні Process Registry IDs або явний exemption, outcome evidence, capability gaps.

## 9. Tenant, Policy та Security
Межі організації, дозволи, секрети, audit, retention, доступи.

## 10. Unified Workspace
Завдання користувача, screens, API, blocked/error states, QA.

## 11. Operations та Recovery
Activation, provisioning, migrations, jobs, monitoring, external effects, rollback.

## 12. Готовність і докази
D0–D5 незалежно від manifest version; UNKNOWN за відсутності незалежного доказу. Required: exact SHA, tenant, environment, readback, acceptance.

## 13. Roadmap, debt, decisions
Блокери, наступний E2E, ADR, architecture exemptions.

## 14. Карта джерел
Посилання на `module.php`, resources/contracts, Process Registry, generated reference, tests та runbooks.