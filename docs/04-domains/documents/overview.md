---
title: Огляд домену Documents
description: Керований процес запиту підпису із чіткою межею між запитом і підтвердженим фактом підписання.
status: active
updated: 2026-10-09
kind: domain
contract: domain-v1
version: 0.1.0
---

# Огляд домену Documents

Documents `0.1.0` є опціональним адаптером до **Platform Documents** для керованого COS Federation workflow. Збереження документів, файлів, версій, запитів та журналу аудиту залишається у Platform Documents.

## Призначення

```text
Goal → Approved Plan → Independent Approval → Action
                                                ↓
                         documents.signature.request
                                                ↓
                         Platform Documents requestSignature
                                                ↓
                         status=requested (not signed)
                                                ↓
                         Human signer / trusted signing provider
                                                ↓
                         Verified signature (not automated in V0.1)
```

`documents.signature.request` через Canonical Action запускає **запит на підпис**, а не здійснює підпис. Права модулів і організацій, типи даних, Approval, виконання та idempotency контролюються ядром COS.

## Захисні правила

- `status=requested`, `signature_reference`, `signed_by` і успішна Action окремо не доводять, що документ підписаний.
- Модуль вимкнений за замовчуванням. Для роботи через Federation потрібні tenant activation та незалежне Approval.
- Запит створюється тільки для tenant-owned документа із `signer_id` та числовим ID автентифікованого ініціатора.
- Повторення того самого idempotency key не створює ще один запит; чужі документи не змінюються.
- Підтвердження підпису від КЕП/e-sign провайдера або перевіреного людського workflow ще **не інтегровано**.
- Критерій `documents.run_linked_signatures` лишається `unverifiable`, доки немає перевіреного signed outcome.

## Версія та реалізація

Module ID `documents`; version `0.1.0`; Kernel `0.11.x`; execution binding `action:documents.signature.request`. Live signature confirmation disabled.

[Federation business outcomes](../../03-architecture/federation-trusted-goal-outcomes.md) та [Модулі й capabilities](../../12-reference/module-capabilities.md).
