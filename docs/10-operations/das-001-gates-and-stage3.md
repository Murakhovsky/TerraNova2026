---
title: DAS-001 - compliance gates and Stage 3 delivery
description: Ownership, Process Registry, evidence and PR gate rules for unified main
status: active
updated: 2026-11-24
kind: operations
---

# DAS-001: єдина контрольна точка

Це критерії перевірки, а не декларація D3–D5 готовності. Джерела істини: `app/Domains/*/module.php`, `resources/processes/*.json`, `docs/.vitepress/capability-debt.json` і Domain Passports.

## Stage 2: обов'язкові gates

1. **Ownership:** кожен typed Capability належить одному Domain та одному компоненту паспорта, namespace збігається з `owner_domain`.
2. **Contracts:** input/output JSON schemas, permission, side-effects, approval, idempotency, tests та реальний adapter/handler. Декларація не є доказом виконання.
3. **Execution:** `query:` і `command:` проходять єдиний Gateway; `action:` проходить canonical Workflow/Approval; `read:` потребує окремої перевірки.
4. **Cross-domain:** declared requires/provides, реальний source port, власник і counterpart. Foreign process step потребує declared requires boundary. Чужі таблиці напряму не мутуються.
5. **Process Registry:** кожен крок посилається на capability власного Domain або explicit gap, який існує рівно один раз у capability-debt registry.
6. **Passport:** components і process_ids збігаються з manifest та Process Registry. D3–D5 тільки з незалежним reviewer, exact SHA, tenant, environment та native readback.
7. **Tests:** позитивні, negative permission, cross-tenant, replay/idempotency, provider failure та recovery. CI green не дорівнює production acceptance.

Запуск: `npm run docs:check`, `php tests/architecture/das_001_direct_binding_coverage.php`, `php tests/architecture/das_001_domain_boundaries.php --self-test`, `bash bin/verify fast`.

Baseline задає стелі технічного боргу, а не підтверджену працездатність. Відомі gaps у Sales/Diagnostic залишаються відкритими до справжніх контрактів і тестів.

## Stage 3: відновлення Product, Marketing, Finance

Всі нові PR починаються від актуального `main`, один завершений вертикальний use case на PR. Старі stacked PR слід переносити вибірково, без перезапису новіших CI/runtime/generated файлів.

| Domain | Перший інкремент | Критерій прийняття |
| --- | --- | --- |
| Product Management | Shopify import preview/apply readback та provider identity | Tenant isolation, approval для write, native Product readback |
| Marketing | M2 creative drafts і analyst proposals | Typed contracts, campaign ownership, без несанкціонованого Meta write |
| Finance | F1.2 spend evidence та F1.3 approved import | Finance-owned immutable ledger, Marketing read-only port, approval і replay |

Перевірити DAS-001 gates, review, маленький PR, merge лише при зелених перевірках. Для D3/D4/D5 потрібен окремий evidence за `docs/10-operations/das-001-domain-acceptance.md`. Не підвищувати readiness лише за результатом статичних тестів.
