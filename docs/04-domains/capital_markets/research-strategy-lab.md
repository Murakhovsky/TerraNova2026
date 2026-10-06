---
title: Лабораторія досліджень і стратегій
description: Керований дослідницький runtime Capital Markets для гіпотез, експериментів, replay, OOS, версіонування стратегій, scorecard та promotion gates.
status: active
updated: 2026-10-07
kind: domain
contract: domain-v1
---

# Лабораторія досліджень і стратегій

Capital Markets `0.8.0` додає керований дослідницький runtime поверх наявних vertical slices Tokenized Equity H1/H2 та Crypto Spot / Perpetual H4/H5/H6.

Мета цього шару не в тому, щоб AI «обирала угоди». Його задача — зробити дослідження відтворюваними, аудитованими та придатними до повторного використання.

## Канонічний lineage

```text
ResearchHypothesis
    ↓
ResearchDataset (frozen snapshot)
    ↓
ResearchExperiment
    ↓
StrategyVersion
    ↓
Backtest / Replay
    ↓
TRAIN / VALIDATION / OUT_OF_SAMPLE
    ↓
ResearchResult
    ↓
StrategyScorecard
    ↓
Promotion Gate
    ↓
PAPER / LIMITED_LIVE decision boundary
    ↓
ResearchKnowledge / RejectedHypothesisRecord
```

Завершені результати, frozen datasets та використані версії стратегій є доказами. Вони не переписуються після незручного результату.

## Історичний replay

Replay H4/H5/H6 використовує історичні `MarketSnapshot` і повторно використовує production stack Capital Markets:

- `SpotPerpetualMarketStateFactory`;
- `RelativeValueEconomicsCalculator`;
- `RelativeValueOpportunityEvaluator`.

Replay layer не реалізує другу формулу P&L.

Backtest, придатний для promotion, вимагає явних fees та slippage. Replay guards відхиляють дані, availability timestamp яких пізніший за simulated timestamp.

## Ізоляція даних

Дослідницькі partition задаються явно:

```text
TRAIN
VALIDATION
OUT_OF_SAMPLE
```

Вони не можуть перекриватися. Після OOS tuning не може повторно використати той самий OOS period так, ніби це все ще unseen evidence. Невдалий або вже використаний OOS window вимагає нової StrategyVersion та/або нового OOS period.

## Ковзна перевірка Walk-forward

Лабораторія генерує rolling train/test windows і агрегує стабільність результатів. Parameter sensitivity піднімає `OVERFIT_RISK`, якщо performance існує лише у вузькому parameter peak.

## Керування стратегіями

`StrategyVersion` є immutable та фіксує ідентичність logic/configuration.

`StrategyScorecard` об’єднує versioned dimensions: profitability, consistency, risk, execution quality, capital efficiency, capacity, robustness, data confidence та operational complexity.

Високий score не обходить promotion criteria.

Підтримувані переходи promotion є deterministic та auditable:

```text
RESEARCH → BACKTEST
BACKTEST → OOS
OOS → PAPER
PAPER → LIMITED_LIVE
LIMITED_LIVE → VALIDATED
VALIDATED → SCALE
```

Live Trading у Capital Markets 0.8.0 залишається вимкненим.

## Негативні результати досліджень

Відхилені гіпотези зберігаються разом із reason, evidence, experiments, market conditions, data limitations та optional reopen conditions.

Повторно використовуваний `ResearchKnowledge` містить як позитивні, так і негативні findings. Duplicate detection перевіряє нові гіпотези проти попередніх досліджень, включно з rejected hypotheses.

## Дослідницький агент

Capital Markets Research Agent зареєстрований у канонічному Agent Runtime.

Він може:

- аналізувати formal hypotheses, experiments і knowledge;
- аналізувати H4/H5/H6 funding та basis evidence;
- шукати попередні дослідження;
- створювати draft hypothesis;
- створювати draft experiment.

Він не може:

- активувати Live Trading;
- змінювати risk або capital limits;
- мутувати completed results;
- мутувати frozen datasets;
- мутувати використані strategy versions;
- обходити promotion gate.

Базове правило:

```text
AI proposes.
Deterministic engines test.
Data decides.
```

## Операторська поверхня

Workspace:

```text
/capital-markets/research
```

Workspace показує hypotheses, experiments, backtest runtime, OOS runs, scorecards, promotion decisions, rejected hypotheses та reusable knowledge.

Основні API surfaces:

```text
GET  /api/v1/capital-markets/research
POST /api/v1/capital-markets/research/hypotheses
POST /api/v1/capital-markets/research/datasets
POST /api/v1/capital-markets/research/experiments
POST /api/v1/capital-markets/research/backtests
POST /api/v1/capital-markets/research/backtests/run
POST /api/v1/capital-markets/research/walk-forward
POST /api/v1/capital-markets/research/agent/run
POST /api/v1/capital-markets/strategies/{id}/scorecard
POST /api/v1/capital-markets/strategies/{id}/promotion-request
```

Mutations потребують tenant context, активованого модуля, явної Capital Markets research capability та CSRF.

## Послідовність ручного acceptance

1. Відкрити `/capital-markets/research`.
2. Створити H4 або H5 hypothesis з явними economic reason, edge source, success criteria та failure criteria.
3. Зафіксувати dataset і перевірити створення deterministic snapshot hash.
4. Створити Strategy v1 та experiment для frozen dataset.
5. Поставити в queue або запустити TRAIN backtest з явними fees та slippage.
6. Перевірити persisted backtest run та immutable result.
7. Створити Strategy v2, якщо parameters або logic змінилися.
8. Запустити OOS з незмінними frozen criteria/parameters для цієї strategy version.
9. Переконатися, що OOS run збережений і не може бути повторно використаний після tuning.
10. Створити scorecard і promotion request.
11. Перевірити deterministic рішення `PASSED / FAILED / MANUAL_REVIEW_REQUIRED`.
12. Записати позитивний або негативний knowledge.
13. Запустити Research Agent і перевірити, що він може створювати лише research drafts, а не Live actions.
