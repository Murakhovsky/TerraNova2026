---
title: Навігація та дозволи
description: Навігація з урахуванням модулів і прав доступу без підміни безпеки прихованими кнопками.
status: active
updated: 2026-09-16
kind: ui
---

# Навігація та дозволи

Navigation у COS відображає доступну продуктову поверхню, але не є security boundary (межею безпеки).

## Вхідні дані навігації

Visible navigation може залежати від:

```text
Deployed module
+ tenant activation
+ user capability / permission
+ current interface surface
+ relevant context
```

Вимкнений module не повинен залишати мертві меню або routes, а недоступна capability не повинна показувати користувачу дію, яку runtime гарантовано відхилить.

## Правило безпеки

```text
Hidden button ≠ permission check
```

UI приховує або disables недоступну Action для нормального UX. Application/runtime boundary **повторно й авторитетно** перевіряє permission або Policy перед мутацією.

## Навігація, якою володіє модуль

Navigation contributions конкретного Domain/module повинні надходити через extension/runtime mechanisms і мати власника.

Спільний Web shell не повинен містити hardcoded knowledge про кожен майбутній Domain. Інакше модульність закінчується рівно там, де починається головне меню, що було б майже поетично, але технічно сумно.

## Прямі посилання

Прямий URL має проходити ті самі tenant/auth/capability checks, що й navigation click.

Неможливість побачити пункт меню не означає неможливість вручну ввести адресу. Браузери все ще мають адресний рядок і, схоже, не планують відмовлятися від цієї небезпечної свободи.

## Порожні та недоступні стани

UI має явно відрізняти:

- module не розгорнутий;
- module не активований для tenant;
- capability не дозволена користувачу;
- дані відсутні або порожні;
- тимчасову помилку сервісу.

Це допомагає diagnostics і не змушує користувача вважати кожний blank screen філософським висловлюванням дизайнера.

## Пов’язані сторінки

- [Довідник дозволів і можливостей](../12-reference/permissions-capabilities.md)
- [Extension Runtime](../03-architecture/extension-runtime.md)
- [Модель робочого простору](./workspace-model.md)

## Інваріант

> Навігація показує доступність. Безпеку визначають перевірки authorization, capability та Policy на авторитетній межі виконання.
