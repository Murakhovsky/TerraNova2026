---
title: Додавання Agent
description: Як додати AI Agent як керовану логіку прийняття рішень без неконтрольованих повноважень на мутацію.
status: active
updated: 2026-09-16
kind: how-to
---

# Додавання Agent

У COS Agent є decision component (компонентом прийняття рішень), а не привілейованим користувачем із прямим доступом до бази та integrations.

## Обов’язковий шлях

```text
Business Event / Request
        ↓
Domain-owned context
        ↓
Agent
        ↓
Structured proposal
        ↓
Policy
   ├─ AUTO
   ├─ APPROVAL_REQUIRED
   └─ DENIED
        ↓
Action handler / Use Case
        ↓
Result Event + Audit
```

## Послідовність

1. Визначте owning Domain і конкретне рішення, яке Agent допомагає приймати.
2. Опишіть мінімальний context contract. Не передавайте Agent всю tenant database «про всяк випадок».
3. Визначте structured output schema для proposal та decision evidence.
4. Дозвольте лише потрібні tools/ports.
5. Додайте Policy, яка визначає `AUTO`, `APPROVAL_REQUIRED` або `DENIED`.
6. Мутацію виконує Application Use Case або Action handler, а не Agent transport.
7. Запишіть audit: context references, agent/version, proposal, Policy result, Approval та execution result.
8. Додайте evaluation cases для invalid output, insufficient context, unsafe proposal та retry/idempotency behavior.

## Обмеження

- Agent не погоджує власну Action.
- Sensitive context не зберігається без redaction, якщо немає обґрунтованої потреби.
- Tool permission є окремою authority boundary від здатності моделі запропонувати tool call.
- Deterministic Domain invariant не переноситься в prompt лише тому, що LLM виглядає сучасніше за `if`.
- Provider routing і secrets залишаються за межами Domain Agent definition.

## Перевірка

Перевірте щонайменше:

- мінімальність і tenant scope контексту;
- redaction;
- structured schema validation;
- unknown/forbidden Action proposals;
- Policy deny та Approval path;
- provider failure/fallback;
- відсутність прямого mutation path з Agent;
- audit/correlation;
- evaluation fixtures.

## Пов’язані сторінки

- [Середовище виконання Agent](../06-ai-agents/agent-runtime.md)
- [Контекст та інструменти](../06-ai-agents/context-and-tools.md)
- [Керування LLM](../06-ai-agents/llm-governance.md)
- [Політики та погодження](../05-runtime/policies-and-approvals.md)
