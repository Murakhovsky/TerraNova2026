---
title: Платформа форм
description: Канонічний Symfony Forms runtime для typed input DTO, валідації, полів, autocomplete, dropzone, modal forms, чернеток і mobile UX.
status: active
updated: 2026-09-21
kind: architecture
---

# Платформа форм

Wave 12.8 вводить єдиний Forms Platform для COS Experience Platform.

Форма є Web adapter, а не способом напряму мутувати Domain Entity.

```text
GET
↓
Query / read model
↓
Input DTO
↓
Symfony Form
↓
FormView
↓
Twig

POST
↓
Symfony Form
↓
Symfony Validator
↓
Input DTO
↓
Command
↓
Application Handler
↓
Domain
↓
303 Redirect
```

## Контракт вхідних даних

Presentation DTO реалізує `FormInputDto`.

DTO:

- належить Web/Application boundary;
- не є Doctrine Entity;
- не містить Repository;
- не містить business behavior;
- не виконує Command самостійно;
- містить лише дані, потрібні конкретному use case.

Для edit-flow дані спочатку читаються Query, після чого мапляться в окремий edit DTO.

Заборонено:

```text
Form
↓
Doctrine Entity
↓
flush()
```

Канонічно:

```text
Form
↓
Input DTO
↓
Command
↓
Handler
```

## Валідація

Шари валідації залишаються розділеними:

```text
Browser hints
↓
Symfony Validator
↓
Application validation
↓
Domain invariants
```

Browser `required`, `type=email`, `inputmode` та інші hints покращують UX, але не є security boundary.

`FormErrorSummary` збирає server validation errors у presentation-friendly список.

`CosValidationSummary` показує summary через semantic alert і не знає про конкретний Domain.

Поля `CosInput`, `CosSelect` та `CosTextarea` підтримують:

- explicit label association;
- help text;
- field errors;
- `aria-invalid`;
- `aria-describedby`;
- required state;
- mobile keyboard hints там, де вони застосовні.

## Автодоповнення

Symfony UX Autocomplete є canonical enhancement для choice/search fields.

Для малого набору options:

```text
ChoiceType
+
autocomplete=true
```

Для high-cardinality даних джерело options повинно йти через Application Query або Search Provider.

Domain UI не повинен напряму залежати від Tom Select.

Doctrine Entity не є default UI model лише заради autocomplete.

## Завантаження файлів

Symfony UX `DropzoneType` є canonical browser adapter для drag-and-drop file input.

Dropzone відповідає лише за interaction.

Security pipeline лишається server-side:

```text
Upload
↓
Temporary quarantine
↓
size / MIME
↓
quota
↓
malware scan
↓
storage
↓
async processing
```

Default UX Dropzone stylesheet вимкнений; вигляд контролюється COS semantic tokens.

## Залежні секції

`conditional-fields` Stimulus controller може показувати або приховувати presentation sections залежно від значення source field.

Він не визначає business validity.

Server Form, Application та Domain повторно перевіряють дані незалежно від того, що було приховано в browser UI.

## Модальні форми

Modal/Drawer form використовує той самий Symfony Form contract.

Для server-loaded форми preferred composition:

```text
CosModal / CosDrawer
↓
Turbo Frame
↓
Symfony Form
↓
Input DTO
```

Validation failure повертає server-rendered form fragment.

Successful state-changing operation завершується Command і `303 Redirect`.

Stimulus керує open/close, focus, dirty state та browser ergonomics, але не business mutation.

## Незбережені зміни

`form-state` controller підтримує dirty state.

Він:

- порівнює browser form state із baseline;
- попереджає при `beforeunload`;
- перехоплює Turbo navigation;
- після submit/reset очищає dirty state;
- диспатчить `cos:form-dirty`.

Це browser behavior.

Controller не робить `fetch()`, не викликає `/api/*` і не зберігає business payload у `localStorage`.

## Чернетки

Presentation policy:

```text
none
manual
autosave
```

В PHP це `FormDraftPolicy`.

Stimulus не зберігає чернетку самостійно.

Для `manual` або `autosave` він диспатчить:

```text
cos:draft-save-requested
```

Збереження реалізує server/Application adapter конкретного use case.

Таким чином draft storage, authorization, tenant scope і conflict policy не витікають у browser controller.

## Мобільна форма

Forms Platform вимагає:

- коректний `inputmode`;
- `autocomplete`;
- `type=email` / `type=tel`;
- native date/time controls там, де вони достатні;
- file input із camera capability лише коли use case цього потребує;
- sticky action area;
- safe-area inset;
- one-column composition на вузькому viewport;
- focus/scroll до invalid field;
- touch target не менше canonical mobile minimum.

Sticky Save є layout behavior, а не окремою mobile codebase.

## Межі

Forms Platform не може:

- напряму мутувати aggregate;
- викликати Repository з Twig;
- робити internal REST call із Symfony Web;
- класти business rules у Stimulus;
- використовувати browser storage як authoritative draft store;
- довіряти client-side validation;
- обходити CSRF або backend authorization;
- робити upload UI security boundary.

## Каталог

`/dev/ui` показує:

- Symfony Form на typed DTO;
- validation summary;
- help/error semantics;
- UX Autocomplete;
- UX Dropzone;
- conditional section;
- dirty/manual draft behavior;
- modal form;
- mobile sticky actions.

## Перевірка

CI перевіряє:

- DTO/Form boundary;
- Validator integration;
- UX adapter wiring;
- presentation-only Stimulus controllers;
- semantic field errors;
- token-only form CSS;
- mobile rules;
- real Symfony Form submit через `cos:web:forms:smoke`.
