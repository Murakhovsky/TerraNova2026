---
title: Trusted Market State → Tokenized Equity Paper Result
domain: capital_markets
process: capital-markets.tokenized-equity-paper-cycle
status: as-is
updated: 2026-10-06
kind: workflow
contract: workflow-v2
process_state: as-is
process_id: capital-markets.tokenized-equity-paper-cycle
description: "Канонічний процес від довіреного Market State до перевіреної H1/H2 можливості, pre-funded paper execution та зафіксованого P&L."
---

# Довірений Market State → результат Tokenized Equity Paper

Цей workflow описує фактичний Vertical Slice №1 для H1 Tokenized Equity Dislocation та H2 Cross-Venue Tokenized Equity Arbitrage.

## Бізнес-мета

Перетворювати довірені та економічно порівнювані market states у відтворювані H1/H2 research opportunities з реалістичним net edge, deterministic risk assessment і безпечним paper execution. Результатом є не прогноз ціни, а перевірений evidence про те, чи існувала виконувана ринкова неефективність після liquidity, fees, quote normalization, pre-funded inventory та execution constraints.

## Учасники

- Capital Markets research runtime, який виявляє H1/H2 candidates і рахує economics;
- deterministic Risk Engine, який застосовує capital/risk/kill-switch constraints;
- Paper Execution runtime, який повторно перевіряє ринок, резервує pre-funded balances і моделює дві ноги;
- оператор Capital Markets, який керує market sources, economic relationships, paper portfolio та venue balances.

## Карта коду

| Етап | Канонічна реалізація |
| --- | --- |
| H1/H2 detection | `app/Domains/CapitalMarkets/Domain/Service/TokenizedEquitySpreadDetector.php` |
| Quote conversion | `app/Domains/CapitalMarkets/Application/Service/TrustedConversionRateResolver.php` |
| Net economics | `app/Domains/CapitalMarkets/Domain/Service/NetEconomicsEngine.php` |
| Opportunity orchestration | `app/Domains/CapitalMarkets/Application/Service/TokenizedEquityVerticalSliceService.php` |
| Deterministic risk | `app/Domains/CapitalMarkets/Domain/Service/TokenizedEquityRiskEngine.php` |
| Paper execution | `app/Domains/CapitalMarkets/Application/Service/TokenizedEquityPaperExecutionService.php` |
| Capital / inventory reservation | `app/Domains/CapitalMarkets/Infrastructure/Persistence/MySql/MysqlTokenizedEquityVerticalSliceRepository.php` |
| Double-entry ledger | `app/Domains/CapitalMarkets/Domain/Ledger/LedgerTransaction.php` |
| Process Registry | `resources/processes/capital-markets-tokenized-equity-paper-cycle.json` |

## Процес

<ProcessDiagram process-id="capital-markets.tokenized-equity-paper-cycle" />

Діаграма генерується з канонічного Process Registry і показує фактичний `as-is` шлях від trusted MarketState до H1/H2 research result та guarded H2 paper execution.

## Представлення відповідальності

<ProcessDiagram process-id="capital-markets.tokenized-equity-paper-cycle" view="ownership" direction="LR" />

## Представлення можливостей

<ProcessDiagram process-id="capital-markets.tokenized-equity-paper-cycle" view="capability" direction="LR" />

## Потік

```text
Trusted Market State
→ Economic Relationship / Quote Normalization
→ Spread Candidate
→ Net Economics
→ Opportunity
→ Deterministic Risk
→ Paper Capital + Venue Inventory Reservation
→ Current-State Revalidation
→ Two-Leg Paper Execution
→ Asset-Aware Double-Entry Ledger
→ Realized Paper P&L / Edge Capture
→ Research Evidence
```

## Ключові правила

- H1 може бути research evidence, але paper execution закритий, доки немає реального executable hedge venue.
- H2 використовує pre-funded cash на buy venue та pre-funded token inventory на sell venue.
- BUY оцінюється по ask/VWAP, SELL по bid/VWAP.
- Різні quote currencies не порівнюються без trusted conversion MarketState.
- Gross spread не є profit: fees, executable prices та інші explicit costs віднімаються до Opportunity.
- Opportunity повторно перевіряється перед paper execution і має TTL.
- Ledger балансується окремо для кожного asset.
- Live trading та withdrawals залишаються вимкненими.
