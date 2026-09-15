---
title: Navigation & Permissions
description: Module-aware navigation and permission-sensitive UI without treating hidden buttons as security.
status: active
updated: 2026-09-15
kind: ui
---

# Navigation & Permissions

Navigation у COS відображає доступну product surface, але не є security boundary.

## Navigation inputs

Visible navigation може залежати від:

```text
Deployed module
+ tenant activation
+ user capability / permission
+ current interface surface
+ relevant context
```

Вимкнений module не повинен залишати мертві меню/routes, а недоступна capability не повинна рекламувати користувачу дію, яку runtime гарантовано відхилить.

## Security rule

```text
Hidden button ≠ permission check
```

UI приховує або disables недоступну action для нормального UX. Application/runtime boundary **повторно і авторитетно** перевіряє permission/policy перед mutation.

## Module-owned navigation

Domain/module navigation contributions мають бути module-owned через extension/runtime mechanisms. Shared Web shell не повинен містити hardcoded knowledge про кожен майбутній Domain.

## Deep links

Прямий URL має проходити ті самі tenant/auth/capability checks, що й navigation click. Неможливість побачити пункт меню не означає неможливість вручну набрати адресу, бо браузери, на жаль, мають адресний рядок.

## Empty / unavailable states

UI має явно відрізняти:

- module not deployed;
- module not activated for tenant;
- capability not permitted;
- data unavailable/empty;
- transient service failure.

Це допомагає diagnostics і не змушує користувача вважати кожний blank screen філософським висловлюванням дизайнера.

## Related

- [Permissions & Capabilities Reference](../12-reference/permissions-capabilities.md)
- [Extension Runtime](../03-architecture/extension-runtime.md)
- [Workspace Model](./workspace-model.md)
