---
title: DAS-001 — план нормалізації Domain Capabilities
description: Порядок погашення підтвердженого процесного боргу Sales та Diagnostic без фальшивих виконуваних контрактів.
status: active
updated: 2026-11-20
kind: architecture
---

# DAS-001 P0 — Нормалізація можливостей доменів

**Джерело істини:** \`docs/.vitepress/capability-debt.json\` (19 items). Ця сторінка визначає критерії закриття, але не дублює перелік кожного процесного gap.

## Sales: 12 прогалин у процесах

Послідовність: lead intake → inbox/canonical state → case/owner → pipeline → activity/follow-up → deal outcome, а також request read → client-case creation → Property match → activity events.

Для кожної операції: спочатку підтвердити, чи існує відповідний Sales-owned Use Case/CommandBus/QueryBus, чи потрібна нова реалізація. Далі надати \`input_schema\`, \`output_schema\`, permission, policy, side-effect level, ідемпотентність, execution binding та integration/negative tests. Property лише читається через declared port; Sales не пише у Property persistence.

## Diagnostic: 7 прогалин у процесах

Послідовність: methodology management → session start → evidence capture → evaluation → result recording → session completion → recommendation acceptance.

Реалізації повинні зберегти канонічний deterministic methodology model, native session/evaluation ownership, tenant isolation та версію evidence. Sales може лише споживати публічний Diagnostic contract, не дублювати scoring engine.

## Умова закриття gap

Gap закривається лише в **одному атомарному PR**:
1. Реальний Domain-owned handler, declared typed Capability Contract, input/output schema та enforceable authorization.
2. Для governed mutation: canonical Kernel Action/Approval/Workflow, без другого executor.
3. Позитивний, негативний, cross-tenant та replay/idempotency тест. Наявний бізнесовий стан перевіряється незалежним native readback.
4. Process Registry step посилається на дійсний capability, debt item вилучається тільки після виконання тестів; generated references регенеруються.
5. CI та operator acceptance залишаються окремими gates. D3–D5 не випливають із unit/integration тестів.

\`docs/.vitepress/das-001-compliance-baseline.json\` тримає ceilings 12 (Sales) і 7 (Diagnostic) та блокує збільшення боргу без окремого архітектурного рішення.
