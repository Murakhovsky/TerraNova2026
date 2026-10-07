---
title: Лабораторія досліджень і стратегій Capital Markets
domain: capital_markets
process: capital-markets.research-strategy-lab
status: as-is
updated: 2026-10-07
kind: workflow
contract: workflow-v2
process_state: as-is
process_id: capital-markets.research-strategy-lab
description: "Канонічний процес від формальної research hypothesis до frozen experiment, historical replay, OOS, scorecard, promotion decision та накопиченого ResearchKnowledge."
---

# Лабораторія досліджень і стратегій Capital Markets

Цей workflow описує фактичний CM-RESEARCH-LAB для H1/H2/H4/H5/H6 і наступних Capital Markets strategies.

## Бізнес-мета

Не дозволяти strategy promotion на підставі ad-hoc графіків або ручного підбору параметрів. Кожне дослідження має формальний lineage:

ResearchHypothesis → Frozen ResearchDataset → StrategyVersion → ResearchExperiment → Backtest / Walk Forward → OUT_OF_SAMPLE → ResearchResult → StrategyScorecard → Promotion / Rejection → ResearchKnowledge

## Учасники

- **Дослідник / аналітик:** формулює гіпотезу, критерії успіху та обмеження.
- **Оператор Capital Markets:** керує наборами даних, чергою backtest та експериментами.
- **Research Agent:** аналізує докази й створює чернетки, але не ухвалює рішення про торгівлю.
- **Детерміновані рушії та Risk Engine:** обчислюють результати й застосовують контрольні умови.
- **Уповноважений користувач:** переглядає promotion-рішення, які потребують ручного погодження.

## Ключові правила

- Dataset snapshot immutable після старту experiment.
- StrategyVersion immutable.
- Completed ResearchResult immutable.
- TRAIN, VALIDATION і OUT_OF_SAMPLE не перекриваються.
- Після tuning невдалий або завершений OOS period не можна повторно використати для тієї самої StrategyVersion.
- Backtest, придатний для promotion, обов'язково має fees та slippage.
- Historical H4/H5/H6 replay використовує production SpotPerpetualMarketStateFactory, RelativeValueEconomicsCalculator та RelativeValueOpportunityEvaluator.
- Негативний результат зберігається як RejectedHypothesisRecord і ResearchKnowledge.
- AI Research Agent може читати evidence і створювати draft hypothesis/experiment, але не може активувати Live, змінювати risk limits або обходити deterministic promotion gates.

## Карта коду

| Етап | Канонічна реалізація |
| --- | --- |
| Formal hypothesis / dataset / experiment | app/Domains/CapitalMarkets/Application/Service/ResearchLabService.php |
| Dataset/OOS isolation | app/Domains/CapitalMarkets/Domain/Research/ResearchIsolationPolicy.php |
| Look-ahead / cost guards | app/Domains/CapitalMarkets/Domain/Research/ReplayDataGuard.php |
| H4/H5/H6 historical replay | app/Domains/CapitalMarkets/Application/Service/RelativeValueHistoricalReplayService.php |
| Backtest orchestration | app/Domains/CapitalMarkets/Application/Service/ResearchBacktestService.php |
| Walk forward | app/Domains/CapitalMarkets/Domain/Research/WalkForwardEngine.php |
| Scorecard | app/Domains/CapitalMarkets/Domain/Research/StrategyScorecardEngine.php |
| Promotion gate | app/Domains/CapitalMarkets/Domain/Research/StrategyPromotionGate.php |
| Demotion | app/Domains/CapitalMarkets/Domain/Research/StrategyDemotionPolicy.php |
| Duplicate research | app/Domains/CapitalMarkets/Domain/Research/ResearchDuplicateDetector.php |
| Research Agent | app/Domains/CapitalMarkets/Automation/Agent/CapitalMarketsResearchAgent.php |
| Research Agent orchestration | app/Domains/CapitalMarkets/Application/Service/CapitalMarketsResearchAgentService.php |
| Operator UI | /capital-markets/research |

## Процес

<ProcessDiagram process-id="capital-markets.research-strategy-lab" />

## Представлення відповідальності

<ProcessDiagram process-id="capital-markets.research-strategy-lab" view="ownership" direction="LR" />

## Представлення можливостей

<ProcessDiagram process-id="capital-markets.research-strategy-lab" view="capability" direction="LR" />

## Потік

Idea → Duplicate Research Search → ResearchHypothesis → Frozen Dataset → Strategy Version → Experiment → Queue / Budget Gate → Backtest / Replay → Parameter Sensitivity → Walk Forward → OOS → Scorecard → Promotion Gate → Paper → Validate / Reject → ResearchKnowledge

## Критерії приймання

Активні tests покривають positive H4 research cycle, negative H6 research cycle, look-ahead rejection, OOS reuse rejection, overfit warning, immutable result rejection та Research Agent authority boundary.

Live trading залишається поза Research Lab і не активується жодним AI/tool path.
