---
title: Каркас перевірки V2
description: Єдина модель перевірки COS V1 від швидких контрактів до production-like runtime.
status: active
updated: 2026-09-29
kind: architecture
contract: architecture-v1
---

# Каркас перевірки V2

## Мета

Каркас перевірки V2 прибирає залежність QA від історичних Wave/V0.x gate і робить одиницею перевірки актуальний invariant або behavior.

## Потік

```text
розробник / CI
      ↓
   bin/verify
      ↓
контракти + unit
      ↓
integration / runtime
      ↓
smoke
      ↓
browser / E2E
```

## Інваріанти

- активні тести не організуються за версіями релізів;
- CI не дублює test orchestration;
- перевірки документації не є прикладними тестами;
- історичні перевірки не запускаються за замовчуванням;
- production runtime має окремий Docker/health gate;
- одна й та сама команда працює локально та в CI.

## Межі

`tests/history` не є test suite. Файл звідти можна повернути лише через переписування актуального інваріанта в active tests.

GitHub workflow відповідає за environment orchestration. `bin/verify` відповідає за вибір і запуск test suite.
