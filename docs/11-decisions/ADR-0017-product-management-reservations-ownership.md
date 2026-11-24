---
title: Product Management E5 — власність замовлення та ручні резерви
description: Межа резервування складу до появи канонічного SalesOrder/OrderLine.
status: draft
updated: 2026-11-24
kind: adr
---

# Product Management E5: резерви та межа замовлення

## Рішення

Product Management є **єдиним власником** `StockReservation`,
`reserved` і `available`. `on_hand` змінюється тільки immutable ledger
operations. Резервування не є продажем, замовленням або платежем.

У чинному коді Sales не виявлено усталеного `SalesOrder/OrderLine`
контракту. **Order ownership не оголошуємо реалізованим.** До появи
окремого власника замовлення дозволено лише manual manager reservation
із документальним `source_ref=manual:<reference>`. Він не підтверджує
існування або фінансовий стан Sales order.

## Контракти та гарантії

- `product_management.stock.reserve`: `variant_id`, `location_id`,
  `quantity` (DECIMAL string) і `source_ref` з префіксом `manual:`;
  змінює тільки `reserved`, потребує tenant manager та idempotency key
- `product_management.stock.release`: `reservation_id` і `reason`;
  знімає весь активний резерв і повторним запитом із тим самим ключем
  повертає первинний receipt, з іншим ключем відхиляється
- За однаковим `source_ref`/Variant/Location існує не більше одного
  резерву, навіть після release. Новий резерв вимагає нового reference
- Порядок фіксації: транзакція, tenant/ownership guard, row lock,
  conditional balance update, reservation row, Kernel event/outbox,
  operation receipt. Shopify-authoritative мапінг блокує локальний writer
- Issue та transfer не можуть списати вже зарезервований available.
  Release не створює stock movement, бо `on_hand` не змінювався
- Автоматичне expiration, partial release, fulfillment, CRM/OMS adapters
  **не реалізовані** і не запускаються без окремого approval/контракту

## Відкладений міждоменний інтерфейс

Сторона, яка стане власником `SalesOrder/OrderLine` (Sales або окремий
OMS після ADR), передає immutable order-line reference через canonical
Capability/Platform Workflow з tenant identity, source version,
idempotency, permission і rollback/compensation semantics. Жодного
прямого SQL між Sales і Product Management. Можливість автоматично
резервувати за customer order залишається заблокованою до формального
рішення про Order ownership.
