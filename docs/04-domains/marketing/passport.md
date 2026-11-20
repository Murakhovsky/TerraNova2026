---
title: Marketing — паспорт домену COS
description: Межі, компоненти, доступні Capabilities і докази приймання Marketing.
status: draft
updated: 2026-11-20
kind: domain
contract: domain-v1
domain_id: marketing
---

# Паспорт домену Marketing

<!-- COS-DOMAIN-PASSPORT-DATA
{
  "schema_version": 1,
  "domain_id": "marketing",
  "manifest": "app/Domains/Marketing/module.php",
  "components": [
    {
      "id": "campaign_planning",
      "capability_ids": ["marketing.campaign.search", "marketing.campaign.get", "marketing.campaign.create"]
    },
    {
      "id": "paid_media_read",
      "capability_ids": ["marketing.paid.account.list", "marketing.paid.insights.query", "marketing.paid.sync.history"]
    },
    {
      "id": "source_evidence",
      "capability_ids": ["marketing.paid.spend.evidence.get"]
    }
  ],
  "process_ids": [],
  "readiness": {
    "level": "D2",
    "evidence": []
  }
}
COS-DOMAIN-PASSPORT-DATA -->

> Паспорт фіксує перевірену на рівні D2 архітектуру, а не факт production-активації. Модуль за замовчуванням вимкнений; M3 платні операції заблоковані.

## 1. Ідентичність

**Domain ID:** `marketing`. **Призначення:** планування рекламних кампаній, збереження імпортованих Meta Ads спостережень і контрольоване джерело даних для Finance.
Маніфест: `app/Domains/Marketing/module.php`. Актуальна версія та набір callable contracts визначаються ним.

## 2. Межі відповідальності

Marketing володіє Campaign Draft, Meta Ad Account binding, provider Campaign/AdSet/Ad, campaign-grain Insights, source revisions і hash. Не володіє Finance Budget, Expense, Invoice, Payment, Sales outcome або Product stock. `reported_spend_decimal` є лише спостереженим значенням провайдера.

## 3. Внутрішні компоненти

| Компонент | Відповідальність | Межа доказів |
| --- | --- | --- |
| `campaign_planning` | Чернетки та пошук внутрішніх кампаній | D2, без незалежного staging |
| `paid_media_read` | Meta read-only snapshots, атомарність імпорту й історія sync | D2, без real Ads Manager acceptance |
| `source_evidence` | Читання одного tenant-bound source snapshot для Finance | D2, не рахунок і не Expense |

Компоненти не є незалежними installable modules.

## 4. Бізнесова модель

Внутрішня маркетингова кампанія не тотожна зовнішній Meta Campaign. Імпортовані Meta об'єкти залишаються `EXTERNALLY_MANAGED`. Snapshot підтверджує збережене спостереження, але не незалежний фінансовий факт.

## 5. Use Cases та Capabilities

Callable capabilities і точні input/output schemas належать manifest, а не цьому тексту. Для `marketing.paid.spend.evidence.get` потрібні account ref та snapshot ref; у відповіді немає токена, raw Meta credentials або права запису у Finance.

## 6. Виконання

Canonical Capability Gateway виконує лише дозволені Query або внутрішні дії. Marketing не створює другого executor. Finance-import через `finance.ad_spend.marketing_snapshot.import` належить **Finance Action/Policy/Approval**, а не Marketing UI чи provider adapter.

## 7. Міждоменні контракти

Product є власником товару, Finance власником бюджетів та фінансового журналу, Marketing власником Meta readback. Є типізований Finance-facing read port, але підтвердження provider billing або Expense відсутнє. Прямий запис Marketing до Finance DB заборонений.

## 8. Процеси та результати

Поточна канонічна Process Registry має explicit Marketing exemption від завершеного наскрізного paid-execution process. Цей паспорт не заявляє M3 або M4/M5 process readiness.

## 9. Tenant, Policy та Security

Усі source reads прив'язані до tenant, Meta account і snapshot ID. Credential reference залишається непрозорим, Meta API token не повертається. Модуль disabled by default; сам факт Capability не надає прав користувачу.

## 10. Робочий простір Unified Workspace

Робочий простір містить Campaigns, Paid Media Accounts, Performance та Action Queue. Інтерфейс відображає source freshness/failures, але не підтверджує реальну витрату чи конверсію. Meta paid writes заблоковані.

## 11. Operations та Recovery

MySQL atomic import відхиляє часткове оновлення при помилці Insights. Окрема історія `FAILED` read-sync attempts не стирає останній підтверджений DB snapshot. Ручна звірка в Ads Manager та remediation до D3 лишається обов'язковою.

## 12. Готовність і докази

**Engineering: D2.** Автоматичні CI, Runtime і MySQL tests підтверджують контрактний source readback, replay, tenant fences. **D3/D4/D5:** не підтверджені. Для підвищення рівня потрібні окремий staging tenant, exact SHA, час, оператор, outcome readback і перевірюване посилання на evidence.

## 13. План розвитку, борги та рішення

Пріоритети: незалежне приймання #435/#440, приймання Finance #436/#442/#449, інтегрована workflow-авторизація, реальний Meta account readback; після цього M2 Product/Hermes і M3 governed paid operations. Наявний D2 source import не легалізує жоден Meta write.

## 14. Карта джерел

- Manifest: `app/Domains/Marketing/module.php`
- Source: `app/Domains/Marketing/Application/Service/MarketingPaidReadService.php`
- Source persistence: `app/Domains/Marketing/Infrastructure/Persistence/MySql/MysqlMarketingPaidReadRepository.php`
- Finance Action: `app/Domains/Finance/Automation/Action/FinanceMarketingSpendImportActionHandler.php`
- Contracts: `resources/contracts/marketing/`, `resources/contracts/finance/`
- Architecture: `docs/04-domains/marketing/overview.md`
