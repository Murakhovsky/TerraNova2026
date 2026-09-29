---
title: Тестування COS
description: Verification Framework V2 для поточних контрактів, поведінки, інтеграцій і production-like сценаріїв.
status: active
updated: 2026-09-29
kind: how-to
contract: how-to-v1
---

# Тестування COS

COS V1 перевіряє **поточні інваріанти та поведінку**, а не історію Wave/V0.x.

## Єдина точка входу

```bash
bash bin/verify fast
bash bin/verify full
bash bin/verify contract
bash bin/verify unit
bash bin/verify smoke
bash bin/verify integration
```

CI викликає ті самі команди. Тестова логіка не повинна жити тільки всередині GitHub Actions YAML.

## Активні рівні

### Contract / architecture

`tests/architecture` містить лише довгоживучі системні інваріанти: dependency direction, Domain ownership, tenant isolation boundaries, persistence ownership, module contracts, cross-domain contracts і zero-legacy gate.

Тест не повинен існувати лише тому, що колись був Wave 13 або V0.38.

### Unit

`tests/unit` перевіряє бізнес-поведінку, value objects, policies, services та deterministic semantics.

Назва тесту описує поведінку, а не реліз, у якому вона з'явилася.

### Integration

`tests/integration` перевіряє властивості, які неможливо довести читанням source: MySQL transactions, locking, migrations, Outbox, adapters і tenant isolation.

### Smoke

`tests/smoke` містить короткі вертикальні сценарії, що відповідають на питання «чи COS живий як система?».

### Browser / E2E

Browser та accessibility перевірки запускаються окремим workflow проти production-like HTTP boundary.

## Historical tests

Version/Wave checks перенесені до `tests/history/`. Вони не запускаються активним runner.

Якщо старий тест захищає актуальний regression, його інваріант треба перенести у поточний Contract/Unit/Integration test з нормальною назвою.

## Мінімальна перевірка

| Зміна | Мінімум |
| --- | --- |
| Domain invariant | Unit |
| Boundary/ownership | Contract |
| Persistence/transaction | Integration + Contract |
| Queue/Outbox | Integration + Smoke |
| API/controller | Contract або Functional + Smoke |
| UI workflow | Browser/E2E + відповідний application test |
| Docs | docs generate/check/build |

## Перевірка

Перед PR:

```bash
composer verify:fast
npm run docs:generate:check
npm run docs:check
```

Перед production cutover:

```bash
composer verify:full
```

та canonical Docker/runtime gate у CI.
