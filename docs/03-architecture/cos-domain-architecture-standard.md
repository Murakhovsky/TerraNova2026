---
title: Стандарт архітектури Business Domains COS (DAS-001)
description: Єдиний стандарт структури, володіння, компонентів, контрактів, виконання та паспортизації бізнес-доменів COS.
status: draft
updated: 2026-11-19
kind: architecture
contract: architecture-v1
---

# DAS-001: Стандарт архітектури Domains COS

**Редакція:** 0.1, на архітектурне приймання. **Сфера:** нові та наявні installable Business Domains.
**Нормативна основа:** ADR-0001 (Kernel / Domain ownership), ADR-0015 (Federation) та чинні module/Capability/Process contracts. Цей документ не змінює їхніх runtime-повноважень.

## 1. Визначення та інваріанти

Domain є bounded context з власною бізнес-семантикою, канонічними сутностями, інваріантами, процесами та станом. Його функціональність відкривається через Use Cases, порти й публічні Capabilities. Директорія, installable module та підтверджена runtime-готовність не є тотожними.

- **Ownership:** один authoritative writer кожного бізнесового стану. Інші Domains використовують reference/projection і declared contracts.
- **Kernel / Domain:** Kernel керує загальними механізмами; Domain визначає бізнесові правила. Жодних `if marketing` або `if product` у Kernel.
- **Єдиний executor:** для governed автономних і ризикових дій застосовується canonical Platform Workflow + Kernel Action, без другого execution engine у Domain.
- **Статуси роздільні:** declared capability ≠ executable binding ≠ tenant permission ≠ фактичний бізнес-результат.
- **Agent-neutral:** Hermes, AI/UI/API/CRON не обходять Domain invariants або permission gates.
- **Source of truth:** машинні факти зчитуються з чинного manifest/registry. Паспорт пояснює бізнесовий сенс, а не дублює технічний каталог.

## 2. Канонічна структура

```text
COS Experience (Unified Workspace / API / agent consumers)
                   |
        Platform policy / Workflow / Action
                   |
       app/Domains/<Name>/module.php
                   |
       Domain-owned public capabilities
                   |
   +---------------+---------------+
   |               |               |
Model & State   Application       Internal
(invariants)    Use Cases         Components
   |               |               |
   +---- Ports / DTO / Events ------+
                   |
     Infrastructure / provider adapters
```

Типовий layout:

```text
app/Domains/<Name>/
  Model/                 Entities, value objects, invariants
  Application/Contract/  Provider/consumer ports
  Application/DTO/       Typed input/output commands
  Application/UseCase/   Business transitions, one authority
  Automation/            Optional Events/Rules/Actions/Agents/Policies
  Infrastructure/        Domain-owned persistence/provider adapters
  Bootstrap/             Optional runtime contributions
  module.php             Installable Domain manifest
resources/contracts/<domain>/   Typed capability input/output schemas
resources/processes/*.json      Canonical business process definitions
docs/04-domains/<id>/passport.md  Domain Passport
```

Папки наводяться як орієнтир: не треба створювати порожні namespace або переносити діючий код заради симетрії.

## 3. Domain, installable module та внутрішній компонент

**Domain** володіє окремою бізнесовою областю. **Installable module** є одиницею ModuleDiscovery/ModuleLifecycle, представленою `app/Domains/*/module.php`. **Internal component / submodule** (Paid Media, Inventory, Pricing) є функціональним розділом того самого Domain.

Поточний `ModuleDiscovery` шукає тільки `app/Domains/*/module.php`. Тому паспорти внутрішніх компонентів не надають їм незалежного install/enable/disable або окремої версії Kernel. Це потребувало б окремого, схваленого розширення lifecycle.

Для кожного внутрішнього компонента в паспорті: `component_id`, business purpose, owned/non-owned entities, internal dependencies, mapping до capabilities, events, interfaces, readiness. Компонент **не стає Domain** просто тому, що в нього багато класів.

## 4. Маніфест та Kernel contributions

Машинний контракт: `ModuleDefinition` → `ModuleManifest` + `ModuleContributions`.

Manifest описує `id`, `name`, `description`, `version`, `schema_version`, `kernel_constraint`, `dependencies`, `enabled_by_default`. Contributions описують runtime module service, job handlers, route contributors, extension services, configuration provisioners, migrations, legacy descriptive capabilities, typed `capability_contracts` та `cross_domain_contracts`.

`DomainModuleInterface` фіксує runtime identity; окремі Kernel interfaces надають optional contributions. Bootstrap/composition з'єднує contracts із concrete adapters. Web або агентні сервіси не створюють альтернативних бізнесових writer.

## 5. Публічні функції та контракти

Публічний `CapabilityContract` визначає `id`, owner, version, kind, input/output schema, execution binding, async, side effect, permission, approval, idempotency, timeout/retry/failure, lifecycle та тести.

Залежно від ризику й походження виклик проходить:
- **Query:** authorized read adapter / Domain Query boundary.
- **Low-risk human command:** існуюча перевірена direct human execution path.
- **External/financial/agent mutation:** Policy + Approval + canonical Platform Workflow + Action/receipt; не безпосередній запис із Hermes.
- **Async:** Kernel Queue/Worker з явним owned handler та correlation/idempotency.

`contributions.capabilities` може містити descriptive surface чи candidate. Сам по собі цей список **не є дозволом виконання**. Паспорт посилається на `module.php`, `CanonicalCapabilityCatalog`, schemas і runtime evidence, але не створює власну копію каталогу.

## 6. Cross-Domain і зовнішні інтеграції

Визначити для кожної межі: consumer, provider authority, business purpose, typed contract/event, allowed data, mutation owner, tenant/auth, failure/idempotency semantics, version compatibility та тести.

Дозволені способи: синхронний порт; бізнесова подія + outbox; read projection; provider adapter. Заборонені: прямий SQL до чужих таблиць, імпорт чужих concrete repositories, прихована міждоменна мутація, прямий provider SDK у бізнесовій моделі.

`contributions.cross_domain_contracts` та `CrossDomainContract` є джерелами перевірюваних декларацій. Runtime-взаємодія має відповідати декларації. Не плутати dependency installability із business contract relationship.

## 7. Взаємодія з платформою

| Platform authority | Domain contribution |
|---|---|
| Module lifecycle / tenant activation | Manifest, dependencies, provisioning |
| Application Command/Query | Use Cases, DTO, порти |
| Event / Outbox | Domain facts, transactional publication |
| Policy / Approval | Business rules і необхідні дозволи |
| Platform Workflow / Action | Typed capabilities, handlers, receipts |
| Queue / Worker | Domain job handlers, без дубльованого worker engine |
| LLM / Agent adapters | Controlled context та tools, незалежні від provider |
| Experience / UI | Navigation, search, commands, screens |
| Audit / Operations | Correlation IDs, mutation receipts, recovery |

## 8. Паспорт Domain

Канонічний шлях: `docs/04-domains/<id>/passport.md`. `overview.md` залишається короткою бізнесовою навігацією.

Паспорт має **14 розділів**: ідентичність; ownership; внутрішні компоненти; бізнес-модель; Use Cases/Capabilities; execution map; міждоменні контракти; процеси; tenant/policy/security; Experience; operations/recovery; readiness/evidence; roadmap/debt; source map.

Паспорт містить машинний блок `COS-DOMAIN-PASSPORT-DATA` з `schema_version`, `domain_id`, `manifest`, `components`, `process_ids`, `readiness`. Він **не** дублює точні версії та capability schema. Цей блок є документальним індексом і перевіряється проти існуючих authoritative registries.

## 9. Readiness без фіктивної готовності

Роздільні осі:
- declaration: absent / declared_only / typed / bound;
- engineering evidence: D0 concept, D1 implementation, D2 tests, D3 staging E2E, D4 user acceptance, D5 production verified;
- deployment: not_deployed / installed / disabled / enabled / blocked;
- outcome: unknown / unverified / verified / failed / reconciliation_required.

У паспортному блоці `readiness.level=UNKNOWN` доти, доки немає достовірних доказів. D3/D4/D5 потребують scenario, exact commit SHA, environment, tenant, performed_at, result, native readback та evidence URL. Версія Domain, зелені unit-тести або process coverage не підвищують readiness.

## 10. Автоматичний контракт паспорта

`node docs/.vitepress/check-domain-passports.mjs` має:
1. Знайти всі installable Domains з `app/Domains/*/module.php`.
2. Вимагати відповідний `passport.md` із 14 секціями та ідентичним `domain_id`.
3. Звірити manifest path, `component_id`, exact capability references з manifest.
4. Звірити `process_ids` з Process Registry або explicit exemption.
5. Відхиляти D3/D4/D5 без незалежних доказів.
6. Не змінювати code/DB/tenant state. Жодних нових executor чи permission grants.

Це **документальний guard**, а не автоматична сертифікація працездатності всього Domain.

## 11. Впровадження

Базові паспорти існуючих Domains створюються з чесним `UNKNOWN` та бізнесовою картою компонентів. Пізніше Domain owners заповнюють ownership, functional mappings і незалежні acceptance evidence.

Нові PR, що додають installable Domain, мають одразу додавати паспорт. Тому Product #433, Marketing #435 і Finance #436 повинні узгодити passport/gate перед merge. Жоден із цих PR не вважається автоматично production-ready.

## 12. Джерела істини та інші стандарти

- `app/Domains/<Name>/module.php`
- `app/Kernel/Module/{ModuleManifest,ModuleContributions,CapabilityContract,CrossDomainContract}.php`
- `resources/contracts/<id>/*`
- `resources/processes/*.json` і `docs/.vitepress/process-coverage-exemptions.json`
- `docs/12-reference/*` (generated; не редагувати вручну)
- `docs/09-development/{adding-a-domain,adding-a-module,adding-a-workflow}.md`
- `docs/11-decisions/ADR-0001-kernel-domain-ownership.md`
- `docs/03-architecture/cross-domain-contracts.md`

**Критерій приймання:** читач або агент за паспортом визначає, для чого існує Domain, хто володіє його станом, як компоненти пов'язані з функціями, хто їх викликає, як вони підпорядковуються Kernel і які результати реально підтверджені.