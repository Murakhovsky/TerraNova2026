---
title: Незалежна звірка NAV і портфельного P&L
description: Вимоги до первинних фінансових джерел, контролю повноти обліку, незалежного затвердження NAV та формування Today/30D P&L.
status: implementation
updated: 2026-10-09
kind: workflow
---

# Незалежна звірка NAV та Today/30D P&L

## Межа достовірності

NAV є бухгалтерською оцінкою `cash + independently marked spot inventory − liabilities`,
а не сумою доступного капіталу, резервів чи доходів від окремих execution.
Підтримуваний автоматом сертифікації контур наразі обмежений **paper portfolio,
однією валютою і long-only spot/tokenized equity**. Short, perpetual, margin,
FX та невідомі типи активів блокуються: для них потрібна окрема фінансова модель,
яка враховує заставу, борги, фінансування та реалізовані/нереалізовані результати.

Технічна можливість прийняти документ **не дорівнює підтвердженню його
автентичності**. Потрібні справжні незалежні виписки провайдерів, перевірка
їхнього походження фінансовим контролером та окреме затвердження.

## Склад пакета документів

Для кожної організації та портфеля потрібні записи з незалежних джерел:

1. `VENUE_BALANCE`: сума грошових коштів на кожному venue в обліковій валюті.
   Має збігатися з `available + reserved` внутрішнього реєстру.
2. `POSITION_BALANCE`: кількість, venue, instrument і position_id кожної
   відкритої позиції. Для нульового портфеля ця категорія може бути порожньою
   лише за наявності окремого підтвердження повноти.
3. `LIABILITY_BALANCE`: актуальний залишок кожного боргового рахунку.
   Відсутність рядків дозволяється лише з підтвердженням відсутності боргів.
4. `EXTERNAL_CASH_FLOW`: усі фактичні зовнішні поповнення та виведення
   зі знаком `+` / `−`, від початку існування портфеля.
5. `ACCOUNT_COVERAGE`: **чотири окремі** підтвердження повноти:
   `VENUES`, `POSITIONS`, `LIABILITIES`, `EXTERNAL_FLOWS`.
   Кожне має `coverage_from`, `coverage_through` і `all_accounts=true`.

Кожне первинне свідчення має `evidence_id`, `provider_id`,
`source_reference`, `effective_at`, `collected_by`,
`source_document_sha256`. Імпорт перевіряє SHA-256 **фактичного файлу**,
не лише JSON з атрибутами. Повторні джерела не перезаписуються.

## Формат прикладу

Приклад грошової виписки:

```json
{
  "evidence_id": "custodian-cash-20261009-1200",
  "kind": "VENUE_BALANCE",
  "provider_id": "independent-custodian",
  "source_reference": "statement-20261009-1200",
  "source_document_sha256": "64-hex-sha256-from-original-file",
  "collected_by": "21",
  "effective_at": "2026-10-09T09:00:00Z",
  "currency": "USD",
  "amount": "100.00",
  "venue_id": "VENUE-1"
}
```

Поле `source_document_sha256` має бути **справжнім** 64-символьним
hex SHA-256. Рядок у прикладі є текстовим шаблоном, а не валідним digest.

Підтвердження повноти рахунків:

```json
{
  "evidence_id": "all-venues-20261009",
  "kind": "ACCOUNT_COVERAGE",
  "provider_id": "independent-custodian",
  "source_reference": "account-census-20261009",
  "source_document_sha256": "64-hex-sha256-from-original-file",
  "collected_by": "21",
  "effective_at": "2026-10-09T09:00:00Z",
  "currency": "USD",
  "amount": "0",
  "coverage_scope": "VENUES",
  "coverage_from": "1970-01-01T00:00:00Z",
  "coverage_through": "2026-10-09T09:00:00Z",
  "all_accounts": true
}
```

Історія має починатися не пізніше створення портфеля; якщо
надійна `created_at` відсутня, застосовується консервативний початок
`1970-01-01`. Для кожної іншої категорії `coverage_scope`
вказується окремо. Підтвердження покриття саме по собі не перевіряє
справжність декларації провайдера: це предмет незалежної фінансової перевірки.

## Виконання

Імпорт первинного свідчення (повторити для всіх файлів):

```bash
php bin/console cos:capital-markets:nav:evidence:import \
  --organization=default --portfolio=paper-master \
  --file=/secure/statement.json --source-file=/secure/original-statement.pdf
```

Проміжна діагностика (залишається `BLOCKED` до погодження):

```bash
php bin/console cos:capital-markets:nav:collect \
  --organization=default --portfolio=paper-master
```

Незалежний затверджувач з чинними `capital_markets.manage`
**або** обома `capital_markets.portfolio.manage` і
`capital_markets.risk.manage_policy`, особисто вивчивши первинні документи,
виконує:

```bash
php bin/console cos:capital-markets:nav:reconcile \
  --organization=default --portfolio=paper-master --reviewer=77 \
  --approve=APPROVE_VERIFIED_INDEPENDENT_EVIDENCE
```

`reviewer` є реальним user_id з active membership. Він не повинен
збігатися з `collected_by` жодного джерела. Просте передавання
параметрів без справжньої незалежної перевірки **не створює**
фінансової достовірності. Слід обмежити доступ до production CLI
і забезпечити перевірку ідентичності оператора поза CLI.

Якщо хоча б один інструмент не підтримується, джерело відсутнє/застаріле,
знайдена розбіжність кількості чи грошей, журнал незбалансований,
не підтверджене повне покриття або reviewer не уповноважений,
результат має `BLOCKED` і **жоден snapshot не записується**.

За повної звірки `PortfolioNavSnapshotProducer` рахує NAV
без floating point через `Decimal`, записує `COMPLETE`,
fingerprints джерел та reviewer у provenance. Історія append-only.

## Формування P&L

`PortfolioNavWindowProjector` використовує **два** підтверджені NAV
біля часових меж: UTC 00:00 та поточний час для Today, аналогічно 30 днів
тому та поточний час для 30D.

```text
Net P&L = NAV closing − NAV opening − (external cash in − external cash out)
```

Вік останньої та початкової оцінки має бути до 900 секунд від відповідної межі.
Розрив у покритті, суперечливі часові позначки, інша валюта або непідтверджений
snapshot означає `UNAVAILABLE`, не нуль. Для історії у 30 днів потрібні
справжні оцінки 30-денного початку, не штучне backfill.

## Експлуатаційні обмеження

- Поки немає підключених до production незалежних джерел (API або автентичних
  документів), фінансове затвердження конкретного портфеля не завершене.
- Ручне CLI-погодження **не є автоматичною потоковою звіркою**.
  Без захищеної ідентичності підписувача й незалежного provider API
  не можна заявляти про автоматичну фінансову сертифікацію.
- Для Perpetual, short, margin, multi-currency та FX потрібен окремий
  reconciler із заставами, фінансуванням, settlement і зобов'язаннями.
- Будь-яка майбутня автоматизація має зберігати ці інваріанти, а не
  подавати прапорці `reconciled=true` як первинне підтвердження.
