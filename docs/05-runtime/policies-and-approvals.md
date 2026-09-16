---
title: Політики та погодження
description: Контроль дозволу між пропозицією Action та фактичною мутацією стану.
status: active
updated: 2026-09-16
kind: runtime
---

# Політики та погодження

Policy (політика дозволу) відповідає на фундаментальне питання:

> **Чи має ця Action право бути виконаною в цьому контексті?**

## Рішення Policy

Kernel використовує три результати:

```text
AUTO
APPROVAL_REQUIRED
DENIED
```

### AUTO

Action може перейти до виконання без ручного підтвердження.

### APPROVAL_REQUIRED

Action блокується до окремого людського рішення.

### DENIED

Action не виконується.

## Default deny

Якщо для Action немає явної відповідної Policy, безпечна поведінка — `DENIED`.

Це особливо важливо для динамічно встановлених Domains та пропозицій, сформованих AI. Новий тип Action не повинен автоматично отримувати право на мутацію лише тому, що хтось забув описати політику.

## ActionPolicy

Policy повинна оцінювати Action разом із контекстом виконання, а не містити код конкретного провайдера.

Типові фактори:

- тип Action;
- організація;
- actor;
- атрибути payload;
- рівень ризику;
- грошовий поріг;
- зовнішнє або внутрішнє призначення;
- поточний бізнес-стан.

## Policy не дорівнює правам доступу до UI

Доступ до сторінки й дозвіл на виконання мутації є різними рівнями.

```text
Can user open page?        → interface authorization
Can proposed mutation run? → Kernel Policy
```

Навіть адміністративний UI не повинен обходити Policy, якщо Action виконується через COS runtime.

## Життєвий цикл Approval

Approval (погодження) є окремою надійно збереженою сутністю.

```text
ActionProposal
   ↓
Action
   ↓
Policy = APPROVAL_REQUIRED
   ↓
Approval(PENDING)
   ↓
Human decision
   ├─ APPROVED → normal Action execution
   └─ REJECTED → terminal / no execution
```

Approval має містити достатньо контексту, щоб людина розуміла:

- що саме буде зроблено;
- над якою сутністю;
- хто або що запропонувало дію;
- чому Policy вимагає погодження;
- ключові ризики й дані пропозиції;
- зв’язок із Event або запуском Agent.

## Межа безпеки Agent

Agent не може:

- змінити `PolicyDecision`;
- самостійно отримати режим `AUTO`;
- погодити власну Action;
- викликати `ActionExecutor` напряму;
- обійти Queue або механізм ідемпотентності.

Agent лише створює структуровану пропозицію.

## Approval не є другим workflow engine

Human Approval має бути вузьким контрольним шлюзом усередині життєвого циклу виконання.

Бізнес-процес, SLA, нагадування та ескалації можуть реагувати на події Approval, але сама сутність Approval не повинна знати весь бізнес-процес.

## Вимоги до аудиту

Для Policy та Approval потрібно зберігати:

- яку Policy оцінено;
- рішення;
- причину;
- посилання на релевантний контекст або його snapshot;
- ідентичність approver;
- час рішення;
- примітку до погодження чи відхилення, якщо вона є;
- ідентифікатор Action;
- correlation ID.

## Рекомендована модель

Domain володіє каталогом Policy для власних Actions. Kernel володіє загальним механізмом їх оцінювання.

```text
Sales action semantics → Sales policy definitions
Policy evaluation      → Kernel
Policy persistence     → Infrastructure adapter
Approval interface     → Web/API/etc.
```

## Інваріант

> Жоден шлях мутації, який вважається COS Action, не повинен мати альтернативного короткого шляху повз Policy та Audit.
