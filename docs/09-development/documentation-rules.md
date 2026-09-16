---
title: Правила документації
description: Правила підтримки документації COS як живої частини codebase.
status: active
updated: 2026-09-16
kind: development
---

# Правила документації

## Канонічна гілка

`main` є єдиною canonical development branch (канонічною гілкою розробки).

```text
main code + tests
        ↓
main machine-readable contracts
        ↓
main generated reference
        ↓
main narrative docs / ADR
        ↓
VitePress publication
```

Нова документація не повинна залежати від checkout іншої branch.

## Коли оновлення обов’язкове

Оновлюйте документацію, якщо змінюється:

- Domain ownership;
- Kernel lifecycle;
- module manifest;
- API або cross-domain contract;
- Event/Policy semantics;
- Agent/LLM governance;
- persistence ownership;
- deployment або migration workflow;
- суттєвий user workflow;
- Process Registry або capability coverage.

## Канонічні контракти сторінок

Шість основних типів сторінок мають versioned structural contracts:

| Тип | Контракт | Призначення |
| --- | --- | --- |
| Концепція | `concept-v1` | mental model і vocabulary |
| Workflow | `workflow-v2` | реальний бізнес-процес, process truth state та Process Diagram |
| Архітектура | `architecture-v1` | boundaries, dependency direction та invariants |
| Domain | `domain-v1` | ownership, model, lifecycle, contracts і code map |
| Інструкція | `how-to-v1` | покрокова developer/operator інструкція з перевіркою |
| Довідник | `reference-v1` | точні факти та source of truth |

ADR залишається окремим decision format:

```text
Context → Decision → Rationale → Alternatives → Consequences → Verification
```

Контракт задається у frontmatter:

```yaml
kind: workflow
contract: workflow-v2
process_state: as-is
```

`docs:check` перевіряє `kind`, структуру H1/H2, frontmatter, обов’язкові секції та структурні вимоги контракту.

Для `how-to-v1` канонічний український заголовок перевірки — `## Перевірка`; checker також зберігає сумісність зі старими `Verify` / `Verification` під час міграції корпусу.

## Стани правди Process

Workflow має формальний `process_state`:

- **`as-is`** — реальний поточний процес, включно з ручними кроками;
- **`to-be`** — цільова модель, яка ще не є поточною поведінкою.

Рівень перевірки не задається автором вручну. Evidence layer виводить його з поточного checkout, наприклад `documented`, `source-verified` або `runtime-verified`.

Не називайте процес runtime-verified лише тому, що в тексті згадано реальний class. Перевірка має випливати з evidence, а не з оптимізму автора.

Повні правила: [Моделювання бізнес-процесів](../02-workflows/business-process-modeling.md).

## Створення сторінок

Нові canonical pages створюйте через template tooling:

```bash
npm run docs:new -- workflow 02-workflows/example-flow.md "Example Flow"
npm run docs:new -- domain 04-domains/example/overview.md "Example Domain"
```

Templates живуть у `docs/.vitepress/templates/` і не публікуються як сторінки документації.

## AS-IS і TARGET

Для narrative architecture/product docs:

- **AS-IS** — підтверджено поточним `main`: code, tests, manifests або executable reference;
- **TARGET** — напрямок розвитку, ще не повністю executable.

Для workflow використовуйте формальне поле `process_state`, а не лише текстові позначки.

## Контракт Workflow

`workflow-v2` вимагає щонайменше:

- business goal;
- actors;
- code map;
- `process_state`;
- `process_id`;
- відповідний запис у Process Registry;
- `ProcessDiagram` projections згідно з моделлю процесу.

Під час поточної мовної міграції machine contract ще приймає історичні англійські назви секцій `Business goal`, `Actors`, `Code map`. Їх міграція в український формат повинна відбуватися разом із checker і всіма canonical workflow pages одним узгодженим етапом.

Diagram відображає process knowledge, але не є самостійним source of truth. Точні commands, events, routes і services залишаються generated reference.

## Згенерований довідник

```text
main manifests / contracts / events / routes
                ↓
PHP documentation generators
                ↓
docs/12-reference/*.md
                ↓
VitePress
```

Generated files не редагуються вручну. Build і CI регенерують їх із поточного checkout.

## Правило версій

Точні versions не дублюються по всьому корпусу.

Human-readable markers допустимі в `Current Scope` і Domain overview, де `docs:check` може звірити їх із `app/Domains/*/module.php`.

Workflow pages не повинні містити stale maturity statements із hardcoded module versions, якщо ту саму істину можна отримати з manifest/reference.

## Мова

Українська є основною мовою кореневого корпусу документації.

У developer section англійський технічний термін допускається, коли він:

- точно відповідає ідентифікатору коду;
- є назвою стандарту, protocol або proper name;
- на першій змістовній появі має український відповідник, якщо це звичайний інженерний термін.

Звичайні англійські слова в українському реченні не вважаються технічною необхідністю.

Повна політика: [Мова та аудиторії](./language-and-audience-policy.md).

## CI

```text
checkout main commit
→ generate reference
→ verify generated reference
→ check links/frontmatter/version contracts
→ check page/process contracts
→ VitePress build
→ deploy /docs
```

Якщо зміна коду робить документацію неправдивою, зміна ще не завершена. Це не бюрократія, а дешевий спосіб не змушувати майбутніх людей реконструювати систему за назвами класів і старими нотатками.
