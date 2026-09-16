---
title: Оцінювання Agent
description: Модель оцінювання якості, надійності, безпеки повноважень та бізнесової користі Agent у COS.
status: active
updated: 2026-09-16
kind: agent
---

# Оцінювання Agent

Agent не можна оцінювати лише за тим, наскільки переконливо він написав відповідь. Для COS важливо, чи він прийняв **корисне, відтворюване й безпечне рішення в межах своїх повноважень**.

## Виміри оцінювання

### Якість рішення

- правильність classification або recommendation;
- релевантність до бізнес-мети;
- якість evidence/reasoning output;
- узгодженість на еквівалентних inputs.

### Дисципліна контексту

- чи використано достатній context;
- чи не підтягнуто зайві sensitive data;
- чи коректні references і provenance;
- чи Agent не вигадує відсутні facts.

### Безпека інструментів і дій

- чи proposal відповідає зареєстрованій capability;
- чи мутація не обходить Policy/Approval;
- чи invalid або unsafe output коректно відхиляється;
- чи аргументи tool проходять schema validation.

### Операційна надійність

- валідність structured output;
- latency;
- частота помилок provider/model;
- fallback behavior;
- token/cost budget;
- наслідки retry та idempotency.

### Бізнесова корисність

- частка прийнятих рекомендацій;
- результат Approval;
- downstream action/result;
- виміряний бізнес-результат, де це можливо;
- вартість false-positive і false-negative.

## Набір оцінювання

Для значущого Agent потрібен versioned evaluation set (версійований набір перевірок):

```text
Input facts/context
+ expected constraints
+ acceptable decisions
+ forbidden decisions
+ expected authority path
+ outcome rubric
```

Не всі кейси мають одну точну відповідь. Rubric може перевіряти допустимий набір рішень та інваріанти.

## Обов’язкові adversarial cases

Перевіряйте щонайменше:

- відсутній context;
- суперечливі facts;
- malicious/untrusted text у context;
- invalid structured output;
- proposal поза capability;
- запит на unauthorized mutation;
- provider timeout/fallback;
- дубльований execution request;
- витік sensitive data.

## Кореляція версій

Evaluation result має бути прив’язаний до:

- agent definition;
- context schema;
- instruction/prompt version;
- output schema;
- model/provider route;
- relevant policy version.

Інакше неможливо зрозуміти, що саме змінило результат.

## Зворотний зв’язок із production

Production telemetry не замінює offline evaluation, але доповнює її:

```text
proposal → policy → approval → execution → outcome
```

Саме повний ланцюг показує, чи Agent реально корисний, а не просто генерує інтелектуально оформлену зайнятість.

## Пов’язані сторінки

- [Середовище виконання Agent](./agent-runtime.md)
- [Модель пам’яті Agent](./memory-model.md)
- [Аудит і діагностика виконання](../05-runtime/audit-and-diagnostics.md)
