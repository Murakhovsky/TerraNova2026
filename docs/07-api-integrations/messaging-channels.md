---
title: Канали повідомлень
description: Telegram та інші канали повідомлень як поверхні доставки над спільними прикладними межами COS.
status: active
updated: 2026-09-16
kind: architecture
---

# Канали повідомлень

Telegram, Viber, email, chat adapters та майбутні messaging surfaces не є окремими бізнес-системами. Вони є каналами доступу до тих самих Application Use Cases (сценаріїв використання застосунку).

## Канонічний шлях каналу

```text
Incoming message / callback
        ↓
Channel adapter
        ↓
Identity + tenant resolution
        ↓
Intent / transport mapping
        ↓
Application DTO / Use Case
        ↓
Domain result
        ↓
Channel-specific rendering
```

## Відповідальність каналу

Шар каналу може володіти:

- розбором update конкретного provider;
- адресацією callback і message;
- форматуванням, кнопками й pagination;
- channel rate limits;
- metadata для retry доставки;
- зіставленням зовнішньої user/chat identity з контекстом ідентичності COS.

Шар каналу не володіє:

- правилами кваліфікації Sales;
- комерційним lifecycle Property;
- семантикою scoring у Diagnostic;
- повноваженнями Agent на мутацію;
- канонічним бізнесовим зберіганням.

## Спільна поведінка

Якщо одна операція доступна через Web, API та Telegram, усі три surfaces повинні викликати однаковий application boundary.

```text
Web ─────┐
API ─────┼→ Use Case → Domain
Telegram ┘
```

Різниця має бути у взаємодії та представленні, а не в бізнес-правилах.

## Межа сумісності

Історичні Telegram ActiveRecord/adapters можуть залишатися compatibility surface під час migration. Нові Domain rules або canonical writes не повинні повертатися туди лише тому, що старий bot уже знає назву таблиці.

## Вихідна доставка

Domain, Event або Automation формує семантичну Action, після чого channel adapter виконує provider-specific delivery. External message id, retry/error metadata та correlation залишаються транспортними фактами.

## Пов’язані сторінки

- [Модель інтеграцій](./integration-model.md)
- [Надійність зовнішніх інтеграцій](./external-reliability.md)
- [Додавання інтеграції](../09-development/adding-an-integration.md)
