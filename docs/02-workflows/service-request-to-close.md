---
title: Service Request → Ticket Close
description: Канонічний процес Service від створення звернення до вирішення та закриття.
status: active
updated: 2026-09-19
kind: workflow
contract: workflow-v1
---

# Service Request → Ticket Close

Процес описує виконуваний шлях Service `0.2.0`.

```text
Request
  ↓
Ticket
  ↓
Assignment
  ↓
SLA
  ├─ normal ─────────→ Resolve
  └─ risk / breach → Escalate → Resolve
                         ↓
                       Close
```

## Кроки

1. **Create Request** створює `ServiceCase` і `Request` в одній транзакції.
2. **Create Ticket** відкриває операційний Ticket для Request.
3. **Assign Ticket** створює історичний Assignment і оновлює current assignee.
4. **Set SLA** фіксує immutable snapshot SLA та обчислені deadlines.
5. **Escalate** за потреби збільшує escalation level і зберігає причину.
6. **Resolve** створює Resolution і переводить Ticket у `resolved`.
7. **Close** дозволений лише після Resolution. Після останнього закритого Ticket автоматично закриваються Request і ServiceCase.

## Надійність

Кожна мутація має tenant scope, authorization, CSRF на HTTP boundary, idempotency key, transaction, lifecycle Event та Audit. Конкурентні зміни одного Ticket серіалізуються row lock-ом, тому `Assign/Escalate/Resolve/Close` не можуть тихо перетерти один одного.

Канонічна машиночитана Process definition: `resources/processes/service-request-to-close.json`.
