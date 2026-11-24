---
title: F1.2 — читання спостережень Marketing у Finance
description: Власність джерельних даних, фінансових спостережень та контроль безпечного виконання.
status: accepted
updated: 2026-11-24
kind: decision
---

# F1.2. Межа Marketing → Finance

Marketing є власником provider observations, Finance є власником observation ledger та reconciliation. Finance оголошує `requires` порт `MarketingSpendEvidenceReadPort`; конкретний adapter працює у Symfony Infrastructure й отримує source snapshot тільки через Marketing service за tenant/account/snapshot.

F1.2 не надає публічного виконання writer через UI/API/AI/CRON. Результат завжди `SOURCE_OBSERVATION_UNRECONCILED`; він не є Expense, оплатою чи підтвердженням Meta. Джерельні hash/revision/window та tenant guard перевіряються, repeated imports не створюють дублікати.

F1.3 окремо має запровадити independently approved canonical Action з source digest pinning, idempotency, recovery та незалежним Finance readback перед будь-яким public import. Пряме читання таблиць Marketing із Finance заборонене.
