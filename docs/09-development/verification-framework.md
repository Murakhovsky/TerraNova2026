---
title: Verification Framework V2
description: Єдина модель перевірки COS V1 від швидких контрактів до production-like runtime.
status: active
updated: 2026-09-29
kind: architecture
contract: architecture-v1
---

# Verification Framework V2

## Мета

Verification Framework V2 прибирає залежність QA від історичних Wave/V0.x gate і робить одиницею перевірки актуальний invariant або behavior.

## Потік

```text
developer / CI
      ↓
   bin/verify
      ↓
Contract + Unit
      ↓
Integration / Runtime
      ↓
Smoke
      ↓
Browser / E2E
```

## Інваріанти

- active tests не організуються за версіями релізів;
- CI не дублює test orchestration;
- documentation checks не є application tests;
- historical verification не запускається за замовчуванням;
- production runtime має окремий Docker/health gate;
- одна й та сама команда працює локально та в CI.

## Межі

`tests/history` не є suite. Файл звідти можна повернути лише через переписування актуального інваріанта в active tests.

GitHub workflow відповідає за environment orchestration. `bin/verify` відповідає за вибір і запуск test suite.
