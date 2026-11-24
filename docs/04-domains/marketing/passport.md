---
title: Marketing — паспорт домену COS
description: Межі, компоненти, доступні Capabilities і докази приймання Marketing.
status: draft
updated: 2026-11-24
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
      "capability_ids": [
        "marketing.campaign.search",
        "marketing.campaign.get",
        "marketing.campaign.create"
      ]
    },
    {
      "id": "paid_media_read",
      "capability_ids": [
        "marketing.paid.account.list",
        "marketing.paid.insights.query",
        "marketing.paid.sync.history",
        "marketing.paid.spend.evidence.get"
      ]
    },
    {
      "id": "creative_planning",
      "capability_ids": [
        "marketing.creative.create",
        "marketing.creative.revise",
        "marketing.creative.list"
      ]
    },
    {
      "id": "analyst_review",
      "capability_ids": [
        "marketing.analyst.context.get",
        "marketing.analyst.run.list",
        "marketing.analyst.run.review",
        "marketing.analyst.run.recover",
        "marketing.analyst.proposal.submit",
        "marketing.analyst.proposal.list",
        "marketing.analyst.proposal.review"
      ]
    },
    {
      "id": "audience_planning",
      "capability_ids": [
        "marketing.audience.save",
        "marketing.audience.list",
        "marketing.adset.save",
        "marketing.adset.list"
      ]
    },
    {
      "id": "product_evidence",
      "capability_ids": [
        "marketing.product.draft_check.get",
        "marketing.product.selector.search"
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

> Паспорт фіксує перевірену на рівні D2 архітектуру, а не факт production-активації. Модуль за замовчуванням вимкнений; M3 платні операції заблоковані.

## 1. Ідентичність

**Domain ID:** `marketing`. **Призначення:** планування рекламних кампаній, збереження імпортованих Meta Ads спостережень у межах власного Marketing Domain.
Маніфест: `app/Domains/Marketing/module.php`. Актуальна версія та набір callable contracts визначаються ним.

## 2. Межі відповідальності

Marketing володіє Campaign Draft, Meta Ad Account binding, provider Campaign/AdSet/Ad, campaign-grain Insights, source revisions і hash. Не володіє Finance Budget, Expense, Invoice, Payment, Sales outcome або Product stock. `reported_spend_decimal` є лише спостереженим значенням провайдера.

## 3. Внутрішні компоненти

| Компонент | Відповідальність | Межа доказів |
| --- | --- | --- |
| `campaign_planning` | Чернетки та пошук внутрішніх кампаній | D2, без незалежного staging |
| `paid_media_read` | Meta read-only snapshots, атомарність імпорту й історія sync | D2, без real Ads Manager acceptance |
| `creative_planning` | Версії креативів, Product refs та опційний TargetSpec чернетки | D2 baseline; TargetSpec потребує CI/приймання, без provider eligibility |
| `analyst_review` | Scoped analyst context, response policy, evidence proposals та незалежні review-рішення | D2, Hermes adapter, admission, journal, independent review і ручне recovery у коді; live binding і monetary cap очікуються |
| `audience_planning` | Версії Audience і AdSet Draft, revision pins, планова потреба бюджету та Workspace editor | Backend CI green; Workspace CI/staging очікуються; без Finance reservation або Meta write |
| `product_evidence` | Пошук Product/SKU, Product/Variant/Price та stock-by-location readback через Product Management read port | D2, без складу/URL/Meta policy acceptance |

Компоненти не є незалежними installable modules.

## 4. Бізнесова модель

Внутрішня маркетингова кампанія не тотожна зовнішній Meta Campaign. Імпортовані Meta об'єкти залишаються `EXTERNALLY_MANAGED`. Snapshot підтверджує збережене спостереження, але не незалежний фінансовий факт.

## 5. Use Cases та Capabilities

Callable capabilities і точні input/output schemas належать manifest, а не цьому тексту. Початкові Query включають Campaign search/get, Meta account list/Insights query/sync history. Finance-facing read-only source evidence Query присутній, але не підтверджує Expense. M2a має Creative create/revise/list і Proposal submit/list/review; M2b має tenant-safe Product draft-check, без запуску Meta.

## 6. Виконання

Canonical Capability Gateway виконує лише дозволені Query або внутрішні дії. Marketing не створює другого executor. Майбутнє Finance import або зовнішня рекламна дія потребуватимуть незалежного **Kernel Action/Policy/Approval**, а не Marketing UI чи provider adapter.

## 7. Міждоменні контракти

Product є власником товару, Finance власником бюджетів та фінансового журналу, Marketing власником Meta readback. Marketing evidence Query та Finance F1/readback уже в `main`; незалежного підтвердження provider billing чи Expense немає. Прямий запис Marketing до Finance DB заборонений.

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

Пріоритети: M1 Meta Ads Manager reconciliation, M2 Hermes analyst та stock/URL/ad-policy signoff, M3 governed paid operations, незалежне D3/D4 приймання. Наявний D2 source import не легалізує жоден Meta write.

## 14. Карта джерел

- Manifest: `app/Domains/Marketing/module.php`
- Source: `app/Domains/Marketing/Application/Service/MarketingPaidReadService.php`
- Source persistence: `app/Domains/Marketing/Infrastructure/Persistence/MySql/MysqlMarketingPaidReadRepository.php`
- Contracts: `resources/contracts/marketing/`
- Architecture: `docs/04-domains/marketing/overview.md`
