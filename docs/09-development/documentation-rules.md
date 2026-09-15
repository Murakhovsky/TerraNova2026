---
title: Documentation Rules
description: Правила підтримки COS documentation як живої частини codebase.
status: active
updated: 2026-09-15
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

## Canonical page contracts

Шість основних page types мають versioned structural contracts:

| Type | Contract | Purpose |
| --- | --- | --- |
| Concept | `concept-v1` | mental model і vocabulary |
| Workflow | `workflow-v1` | business flow від мети до implementation boundary |
| Architecture | `architecture-v1` | boundaries, dependency direction та invariants |
| Domain | `domain-v1` | ownership, model, lifecycle, contracts та code map |
| How-to | `how-to-v1` | покрокова developer/operator інструкція з verification |
| Reference | `reference-v1` | exact facts та source of truth |

ADR лишається окремим decision format: Context → Decision → Rationale → Alternatives → Consequences → Verification.

Contract opt-in задається у frontmatter:

```yaml
kind: workflow
contract: workflow-v1
```

`docs:check` перевіряє відповідність `kind`, H1/H2 structure і type-specific required sections. Contract versioning дозволяє посилювати правила без миттєвого переписування всього documentation corpus.

## Page scaffolding

Нові canonical pages створюйте через template tooling:

```bash
npm run docs:new -- workflow 02-workflows/example-flow.md "Example Flow"
npm run docs:new -- domain 04-domains/example/overview.md "Example Domain"
```

Templates живуть у `docs/.vitepress/templates/` і не публікуються як documentation pages.

## AS-IS vs TARGET

- **AS-IS** — підтверджено current `main` code/tests/manifests.
- **TARGET** — direction, ще не повністю executable.

## Workflow contract

`workflow-v1` вимагає щонайменше `Business goal`, `Actors` і `Code map`. Для складних flows також потрібні trigger/input, decision points, events, failures, invariants і cross-domain calls.

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
→ check page contracts
→ VitePress build
→ deploy /docs
```

Якщо зміна коду робить документацію неправдивою, change не завершений. Це не бюрократія, це мінімальний захист від майбутнього нас, який знову спробує вгадати архітектуру по назві сервісу.
