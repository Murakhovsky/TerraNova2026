---
title: Documents — паспорт домену COS
description: Стандартизований паспорт відповідальності, компонентів, контрактів і готовності домену Documents.
status: draft
updated: 2026-11-19
kind: domain
contract: domain-v1
domain_id: documents
---

# Паспорт домену Documents

<!-- COS-DOMAIN-PASSPORT-DATA
{
  "schema_version": 1,
  "domain_id": "documents",
  "manifest": "app/Domains/Documents/module.php",
  "components": [
    {
      "id": "document_operations",
      "capability_ids": [
        "documents.proposal.prepare"
      ]
    },
    {
      "id": "signatures",
      "capability_ids": [
        "documents.signature.sign",
        "documents.signature.request"
      ]
    }
  ],
  "process_ids": [],
  "readiness": {
    "level": "UNKNOWN",
    "evidence": []
  }
}
COS-DOMAIN-PASSPORT-DATA -->

> **Базовий паспорт DAS-001.** Внутрішня карта компонентів є архітектурною класифікацією, а не твердженням про незалежні installable modules або їхню production-готовність. Рівень приймання UNKNOWN до незалежного E2E.

## 1. Ідентичність

**Domain ID:** `documents`. **Призначення:** Документальні процедури та контроль статусів підпису.
**Джерело версії, schema та runtime contributions:** `app/Domains/Documents/module.php`.
Потрібне окреме підтвердження стану активації конкретного tenant.

## 2. Межі відповідальності

**Власний предмет відповідальності:** Власні документальні дії та запити на підпис.
**Не володіє:** Запит на підпис не є юридично підтвердженим підписом.
Актуальні invariants визначаються Domain Model/Application, а не текстом паспорта.

## 3. Внутрішні компоненти

| component_id | Призначення | Рівень доказів |
| --- | --- | --- |
| `document_operations` | Функціональний розділ `documents`; деталізація capabilities потребує owner review | UNKNOWN |
| `signatures` | Функціональний розділ `documents`; деталізація capabilities потребує owner review | UNKNOWN |

Компоненти не можуть незалежно активуватися без окремо підтвердженого ModuleLifecycle contract.

## 4. Бізнесова модель

Authoritative state, Domain Events та persistence належать Domain. Актуальні aggregate roots, ports, migrations і transitions слід звіряти з кодом. Цей базовий паспорт не декларує готовність кожної з цих частин.

## 5. Use Cases та Capabilities

Точні callable IDs, typed schemas, execution bindings, permissions і lifecycle беруться **тільки** з `app/Domains/Documents/module.php` та generated `docs/12-reference/module-capabilities.md`.
Машинна карта поки не прив'язує capabilities до кожного internal component: `capability_ids=[]` означає **mapping невідомий**, а не відсутність функціоналу.

## 6. Виконання

Human UI/API, AI/Hermes та CRON можуть отримувати доступ лише через дозволені Domain public boundaries. Governed зовнішні дії потребують Kernel Policy/Approval та canonical Workflow/Action. Реальний end-to-end execution не підтверджується наявністю декларації.

## 7. Міждоменні контракти

Канонічні `requires/provides`, provider references, tenant/failure/version semantics визначає `contributions.cross_domain_contracts` у manifest. Прямий запис до чужого Domain заборонений.

## 8. Процеси та результати

Process Registry: поточний main має explicit exemption; відсутність повного процесу не означає runtime acceptance.
Покриття означає **описану процесну модель**, не успішний користувацький сценарій.

## 9. Tenant, Policy та Security

Runtime permissions і tenant activation зчитуються з Kernel та manifest, без автоматичного permission grant із паспорта. Credential та зовнішні секрети не зберігаються в паспорті.

## 10. Робочий простір Unified Workspace

Користувацькі точки входу й заблоковані дії мають резолвитися через спільні capabilities. Наявність screens сама по собі не підтверджує працездатність. Потрібен owner review UX-сценаріїв.

## 11. Operations та Recovery

ModuleLifecycle, migrations, workers та rollback контролюються відповідними Platform mechanisms. Жодної автоматичної активації через цей документ.

## 12. Готовність і докази

**Engineering:** UNKNOWN. **Tenant activation:** не підтверджено. **Outcome:** unverified.
До позначки D3/D4/D5 обов'язкові exact commit SHA, environment, tenant, test/CI URL та native business readback; поточних evidence у цьому паспорті немає.

## 13. План розвитку, борги та рішення

Наступний крок: Domain-owner review бізнесових компонентів, актуальний capability-to-component mapping, перевірка integration boundaries та один незалежний UI → Use Case → Domain readback E2E.
Архітектурні зміни узгоджувати з ADR-0001 і DAS-001.

## 14. Карта джерел

- Manifest: `app/Domains/Documents/module.php`
- Contracts: `resources/contracts/`
- Process Registry: `resources/processes/*.json`
- Generated reference: `docs/12-reference/`
- Domain architecture: `docs/09-development/adding-a-domain.md`
