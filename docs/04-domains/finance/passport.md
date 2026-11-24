---
title: Finance — паспорт домену COS
description: Архітектурний паспорт F1 бюджету й F1.1 спостережень рекламних витрат.
status: draft
updated: 2026-11-20
kind: domain
contract: domain-v1
domain_id: finance
---

# Паспорт домену Finance

<!-- COS-DOMAIN-PASSPORT-DATA
{
  "schema_version": 1,
  "domain_id": "finance",
  "manifest": "app/Domains/Finance/module.php",
  "components": [
    {
      "id": "budgets",
      "capability_ids": [
        "finance.budget.envelope.draft.create",
        "finance.budget.envelope.get",
        "finance.budget.reservation.list",
        "finance.budget.envelope.activate",
        "finance.budget.reservation.reserve",
        "finance.budget.reservation.release",
        "finance.budget.envelope.freeze"
      ]
    },
    {
      "id": "spend_observations",
      "capability_ids": [
        "finance.ad_spend.reconciliation.status"
      ]
    },
    {
      "id": "marketing_spend_readback",
      "capability_ids": []
    },
    {
      "id": "sales_commercial_evidence",
      "capability_ids": [
        "finance.sales.commercial_evidence.read"
      ]
    }
  ],
  "process_ids": [],
  "readiness": {
    "level": "D2",
    "evidence": []
  }
}
COS-DOMAIN-PASSPORT-DATA -->

> D2 засвідчує наявність автоматизованих unit/MySQL acceptance для окремого F1/F1.1 зрізу. Це **не** означає D3 staging, D4 операторське чи D5 production приймання.

## 1. Ідентичність

**Domain ID:** `finance`. **Версія, lifecycle та контракт contributions:** `app/Domains/Finance/module.php`. **Функція:** контроль бюджетних конвертів, резервування та фіксація спостережень щодо рекламних витрат, а не бухгалтерський облік чи реальні платежі. За замовчуванням модуль вимкнено.

## 2. Межі відповідальності

Finance є єдиним authoritative writer для BudgetEnvelope, Reservation, ledger і source SpendObservation. Marketing володіє Campaign, Meta Ad Set, snapshots та рекламними творчими матеріалами. Source spend **не** перетворюється на Finance Expense або підтверджену Payment. Заборонено прямий Marketing SQL доступ до Finance tables, будь-яке несанкціоноване списання або створення конкуруючого execution engine.

## 3. Внутрішні компоненти

| component_id | Володіння | Публічні можливості |
| --- | --- | --- |
| `budgets` | BudgetEnvelope, Reservations, ревізії, ідемпотентні фінансові події | 7 F1 типізованих контрактів |
| `spend_observations` | Незмінні ревізійні спостереження джерела | 1 типізований статус звіряння |
| `sales_commercial_evidence` | Read-only accepted Sales quote evidence, no settlement | 1 typed query |

Наведені компоненти не є незалежно installable Domain.

## 4. Бізнесова модель

BudgetEnvelope має DRAFT/ACTIVE/FROZEN та точні цілочисельні ліміти валюти; транзакційний lock і revision fencing запобігають oversubscription. Резервування та звільнення ідемпотентні й залишають audit receipts. SpendObservation зберігає exact currency/timezone/window/grain/attribution та immutable source revisions. Непідтверджені джерельні дані не змінюють фактичний баланс витрат.

## 5. Use Cases та Capabilities

Машинне джерело callable IDs, input/output schemas, permissions, execution bindings: `app/Domains/Finance/module.php`. Бюджетний компонент публікує draft/create, get, reservations/list і чотири governed Action transitions. Спостереження публікують лише `finance.ad_spend.reconciliation.status`. Паспорт не надає прав виклику й не дублює версії контрактів.

## 6. Виконання

Draft/query проходять дозволений tenant-scoped Gateway; activation/reserve/release/freeze лише через canonical Kernel Action, Policy, незалежний Approval та durable Workflow/receipt. AI/CRON не одержують прямих прав на фінансові мутації. Карантин і ручна reconciliation для сумнівних зовнішніх результатів.

## 7. Міждоменні контракти

Marketing→Finance trusted source ingestion поки **не прийнятий** і не відкритий для автономного доступу. Приватний source observation contract не є public command. Майбутній зв'язок повинен мати declared requires/provides та незалежний review, tenant, origin, reconciliation та version semantics.

## 8. Процеси та результати

Process Registry: поточний явний виняток F1 означає відсутність прийнятого повного paid-spend-to-settlement процесу. Сам факт резерву або звіт Meta не є підтвердженим Expense. Виняток документується в `docs/.vitepress/process-coverage-exemptions.json`.

## 9. Tenant, Policy та Security

Tenant scoping і permissions виконує Kernel; бюджетний Action потребує незалежного Approval. Currency вимірюється цілими minor units, spend observations зберігаються DECIMAL strings без Float. Provider credentials не надходять до Finance state або цього паспорта.

## 10. Робочий простір Unified Workspace

Query та budget draft мають відображатись як окремі дозволені можливості; risk-bearing actions показують `approval_required` і не обходяться кнопками. Завершене тестування браузерного Workspace для F1 не підтверджено.

## 11. Operations та Recovery

Фіксовані mutation receipts, InnoDB row-lock, idempotency і audit є основою перевірок. Потрібні staging workflow replay, crash recovery, independent native readback, production rollback/disable та ознаки provider source drift.

## 12. Готовність і докази

**Engineering D2:** F1/F1.1 unit та реальні MySQL integration/contended concurrency тести у PR #436. **D3/D4/D5:** не підтверджені. **Tenant activation:** production відсутня. **Outcome:** unverified. Паспорт не містить D3+ evidence, тому `readiness.evidence=[]`.

## 13. План розвитку, борги та рішення

P0: прийняти typed Finance→Marketing bridge лише після independent contract; забезпечити governed paid-spend acknowledgement, actual Expense reconciliation, Wallet/Payment лише окремими дозволеними і перевіреними етапами. Пройти DAS-001 compliance та D3 test plan перед будь-яким увімкненням.

## 14. Карта джерел

- Manifest: `app/Domains/Finance/module.php`
- Overview: `docs/04-domains/finance/overview.md`
- Integration: `tests/integration/finance_budget_f1_mysql.php`, `tests/integration/finance_budget_f1_concurrency_mysql.php`, `tests/integration/finance_spend_observations_mysql.php`
- Process exemption: `docs/.vitepress/process-coverage-exemptions.json`
- Release: `docs/10-operations/das-001-domain-acceptance.md`


F1.2 — приватний readback порт між Marketing і Finance. Він навмисно не є public Capability до F1.3 canonical approved Action; D2 не підвищується автоматично.

Sales accepted quote evidence is a read-only source observation via `SalesCommercialEvidencePort`; it does not establish collected revenue or an accounting posting. D2 remains limited to F1/F1.1.
