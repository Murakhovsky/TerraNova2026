---
title: DAS-001 — план нормалізації Domain Capabilities
description: Порядок погашення підтвердженого процесного боргу Sales та Diagnostic без фальшивих виконуваних контрактів.
status: active
updated: 2026-11-24
kind: architecture
---

# DAS-001 P0 — Нормалізація можливостей доменів

**Джерело істини:** `docs/.vitepress/capability-debt.json`. Поточний борг і його кількість обчислюються безпосередньо з реєстру; цей документ не фіксує застарілі лічильники або фіктивну runtime-готовність.

## Sales: послідовне закриття процесних прогалин

Послідовність: lead intake → inbox/canonical state → case/owner → pipeline → activity/follow-up → deal outcome, а також request read → client-case creation → Property match → activity events.

Для кожної операції: спочатку підтвердити, чи існує відповідний Sales-owned Use Case/CommandBus/QueryBus, чи потрібна нова реалізація. Далі надати \`input_schema\`, \`output_schema\`, permission, policy, side-effect level, ідемпотентність, execution binding та integration/negative tests. Property лише читається через declared port; Sales не пише у Property persistence.

## Diagnostic: послідовне закриття процесних прогалин

`diagnostic.session.start` та `diagnostic.evidence.capture` під’єднано до наявних idempotent CommandBus handlers. Evidence Capture має лише human-only direct invocation, суворі input/output schemas, tenant fencing, idempotency та redacted receipt без значень фактів. Решта кроків залишаються у реєстрі боргу до появи перевірених виконуваних контрактів. D3/D4/D5 цим не підтверджуються.

Послідовність: methodology management → session start → evidence capture → evaluation → result recording → session completion → recommendation acceptance.

Реалізації повинні зберегти канонічний deterministic methodology model, native session/evaluation ownership, tenant isolation та версію evidence. Sales може лише споживати публічний Diagnostic contract, не дублювати scoring engine.

## Умова закриття gap

Gap закривається лише в **одному атомарному PR**:
1. Реальний Domain-owned handler, declared typed Capability Contract, input/output schema та enforceable authorization.
2. Для governed mutation: canonical Kernel Action/Approval/Workflow, без другого executor.
3. Позитивний, негативний, cross-tenant та replay/idempotency тест. Наявний бізнесовий стан перевіряється незалежним native readback.
4. Process Registry step посилається на дійсний capability, debt item вилучається тільки після виконання тестів; generated references регенеруються.
5. CI та operator acceptance залишаються окремими gates. D3–D5 не випливають із unit/integration тестів.

`docs/.vitepress/das-001-compliance-baseline.json` зберігає переглянуті per-domain ceilings та блокує неконтрольоване збільшення боргу. Зменшувати ceiling можна лише разом із реальним typed contract, перевірками і регенерацією довідників.

### Канонічне завершення Diagnostic

`diagnostic.session.complete` делегує наявному CompleteDiagnosticCommand і транзакційному DiagnosticRuntimeService. Ідемпотентність природна: одна terminal completion на tenant/session, незалежно від request key; інша сесія є іншим бізнесовим наміром. Публічний receipt містить лише session ID, status, report version та replay flag, звірені з native runtime/report readback. Evaluation і result recording всередині completion не оголошуються окремими callable capabilities. Process Registry відображає одну атомарну completion та окреме незалежне читання persisted report metadata через `diagnostic.report.summary.get`. Дві попередні прогалини відокремлених команд погашено через корекцію процесної моделі, а не через додавання фіктивних mutation handlers. D3–D5 не встановлено.

### Компіляція методології

`diagnostic.methodology.compile` є read-only preflight для точного tenant-owned Studio pack/version. Generic manager permission не замінює `diagnostic.methodology.edit`. Результат містить ідентичність, runtime version, статус і content hash; не розкриває документ методології. Операція не викликає mutable `validate`, regression, publish або activate. Хеш є fingerprint компільованого snapshot, а не доказ publication readiness. Окремий methodology publication gap залишається до захисту immutable publication та атомарної activation.
