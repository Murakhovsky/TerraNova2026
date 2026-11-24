---
title: Контракт джерельного рекламного спостереження Marketing → Finance
description: Розділення Marketing readback, Finance accounting та канонічного контролю міждоменного виконання.
status: draft
updated: 2026-11-19
kind: decision
---

# MKT-ADR-003: Джерельне спостереження не є витратою

Marketing є власником збережених read-only Meta Insights, Finance є власником фінансової звірки, бюджетного стану та Expense. Змішувати ці моделі в один repository або надавати Marketing право змінювати Finance ledgers заборонено.

**Рішення:** Marketing експортує тільки типізований Query \`marketing.paid.spend.evidence.get\`, який читає повний provenance за tenant/account/snapshot, повертає source revision і два окремі факти:
- \`readback_status=MARKETING_DB_SNAPSHOT\`: рядок існує в canonical Marketing read model.
- \`finance_expense_confirmed=false\`: жодного фінансового підтвердження немає.

Припущення про достовірність провайдера **не робиться**. На рівні Finance F1.1 прийнята ознака \`UNVERIFIED_SOURCE\` зберігається, навіть якщо у Marketing є актуальна версія Meta snapshot.

Наступний integration milestone має реалізувати actor-scoped typed Finance-owned importer, який **сам отримує** доказ через canonical Marketing Query / дозволений adapter. Викликач не повинен мати можливості передати довільний JSON як зовнішньо верифіковане джерело. Потрібна коректна ідемпотентність при source revisions, відмова від застарілих версій і незалежна перевірка tenant/account, повноти періоду й валюти.

До інтеграційного приймання Finance PR #436 і Marketing PR #435 залишаються Draft, paid writes вимкнені, немає Meta activation, Finance Expense, Invoice, Payment чи визнаного ROAS.
