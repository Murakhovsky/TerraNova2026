---
title: Optimization Rule → Approved Review → Learning
description: Контрольоване опрацювання правила оптимізації, незалежне погодження і спостереження наступного періоду.
status: active
updated: 2026-11-26
kind: workflow
contract: workflow-v2
process_state: as-is
process_id: marketing.optimization-review-learning
---

# Optimization Rule → Approved Review → Learning

## Бізнес-мета

Замкнути цикл від нативних медіаспостережень до погодженого review, незалежного Outcome і повторного спостереження. Невідомі або змінені дані завершують підготовку без створення бізнес-дії. Кожний запис потребує окремого незалежного Plan та Action approval через canonical Platform Workflow.

## Учасники

Marketing manager, незалежний approver, Federation і Platform Workflow, Marketing runtime.

## Карта коду

- `resources/processes/marketing-optimization-review-learning.json`
- `app/Domains/Marketing/Application/Service/MarketingOptimizationRuleService.php`
- `app/Domains/Marketing/Application/Service/MarketingOptimizationLearningService.php`

## Процес

<ProcessDiagram process-id="marketing.optimization-review-learning" />

## Відповідальність

<ProcessDiagram process-id="marketing.optimization-review-learning" view="ownership" direction="LR" />

## Можливості

<ProcessDiagram process-id="marketing.optimization-review-learning" view="capability" direction="LR" />

Порт `MarketingOptimizationReviewEvidenceReadPort` використовує типізований read-only контракт Federation для вже збережених Goal/Workflow/Action доказів (таблиці належать Platform). Адаптер Marketing звіряє ці докази з нативною квитанцією Marketing. Він не делегує погодження або виконання.

## Межі приймання

Це процес реєстрації спостережень і погодження review, а не доказ фактичної зміни кампанії, експериментальної причинності, виручки або оптимізації витрат. RETROSPECTIVE_ONLY окремо позначає дані до review. Source verification не є production acceptance.
