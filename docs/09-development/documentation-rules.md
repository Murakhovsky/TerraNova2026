---
title: Documentation Rules
description: Правила підтримки COS documentation як живої частини codebase.
status: active
updated: 2026-09-14
kind: development
---

# Documentation Rules

## Canonical branch

`main` є єдиною canonical development branch.

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

## Коли update обов'язковий

Оновлюйте docs, якщо змінюється Domain ownership, Kernel lifecycle, module manifest, API/cross-domain contract, Event/Policy semantics, Agent/LLM governance, persistence ownership, deployment/migration workflow або суттєвий user workflow.

## Page types

- **Concept** — mental model і vocabulary.
- **Workflow** — business flow від мети до implementation boundary.
- **Architecture** — boundaries, dependency direction та invariants.
- **Domain** — ownership/non-ownership, model, lifecycle, contracts, persistence.
- **How-to** — покрокова developer/operator інструкція.
- **Reference** — exact executable facts; генерується, якщо це можливо.
- **ADR** — Context → Decision → Rationale → Alternatives → Consequences → Verification.

## AS-IS vs TARGET

- **AS-IS** — підтверджено current `main` code/tests/manifests.
- **TARGET** — direction, ще не повністю executable.

## Workflow contract

Canonical workflow page повинна мати щонайменше `Business goal`, `Actors` і `Code map`, а для складних flows також trigger/input, decision points, events, failures, invariants і cross-domain calls.

## Generated reference

```text
main manifests / contracts / events / routes
                ↓
PHP documentation generators
                ↓
docs/12-reference/*.md
                ↓
VitePress
```

Generated files не редагуються вручну. Build і CI регенерують їх з current checkout перед publication.

## Version rule

Exact versions не дублюються всюди. Human-readable markers дозволені у `Current Scope` та Domain overview; `docs:check` звіряє їх із `main/app/Domains/*/module.php`.

## CI

```text
checkout main commit
→ generate reference
→ verify generated reference
→ check links/frontmatter/version contracts
→ VitePress build
→ deploy /docs
```

Якщо зміна коду робить документацію неправдивою, change не завершений. Це не бюрократія, це мінімальний захист від майбутнього нас, який знову спробує вгадати архітектуру по назві сервісу.
