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
| Workflow | `workflow-v2` | real business flow + explicit process truth state + Mermaid diagram |
| Architecture | `architecture-v1` | boundaries, dependency direction та invariants |
| Domain | `domain-v1` | ownership, model, lifecycle, contracts та code map |
| How-to | `how-to-v1` | покрокова developer/operator інструкція з verification |
| Reference | `reference-v1` | exact facts та source of truth |

ADR лишається окремим decision format: Context → Decision → Rationale → Alternatives → Consequences → Verification.

Contract opt-in задається у frontmatter:

```yaml
kind: workflow
contract: workflow-v2
process_state: as-is
```

`docs:check` перевіряє відповідність `kind`, H1/H2 structure, required frontmatter, required sections і наявність Mermaid diagram для `workflow-v2`.

## Process truth states

Workflow має один із трьох process truth states:

- **`as-is`** — реальний поточний process; може містити human/manual steps, які COS ще не виконує сам;
- **`to-be`** — цільова модель, не поточна поведінка;
- **`runtime-verified`** — критичні transitions мають explicit executable mapping у current `main`.

Не підвищуйте workflow до `runtime-verified` лише тому, що в ньому згаданий реальний class. Це має бути властивість процесу, а не оптимізм автора.

Повні правила: [Business Process Modeling](../02-workflows/business-process-modeling.md).

## Page scaffolding

Нові canonical pages створюйте через template tooling:

```bash
npm run docs:new -- workflow 02-workflows/example-flow.md "Example Flow"
npm run docs:new -- domain 04-domains/example/overview.md "Example Domain"
```

Templates живуть у `docs/.vitepress/templates/` і не публікуються як documentation pages.

## AS-IS vs TARGET

Для narrative architecture/product docs:

- **AS-IS** — підтверджено current `main` code/tests/manifests;
- **TARGET** — direction, ще не повністю executable.

Для workflow pages використовуйте формальне поле `process_state`, а не лише текстові позначки.

## Workflow contract

`workflow-v2` вимагає щонайменше `Business goal`, `Actors`, `Code map`, `process_state` та один fenced `mermaid` diagram. Для складних flows також потрібні trigger/input, decision points, events, failures, invariants і cross-domain calls.

Mermaid відображає process knowledge, але не є самостійним source of truth. Exact command/event/service inventories залишаються generated reference.

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

Workflow pages не повинні зберігати stale maturity statements із hardcoded module versions, якщо ту саму істину можна виразити через поточний manifest/reference.

## CI

```text
checkout main commit
→ generate reference
→ verify generated reference
→ check links/frontmatter/version contracts
→ check page + workflow contracts
→ VitePress build
→ deploy /docs
```

Якщо зміна коду робить документацію неправдивою, change не завершений. Це не бюрократія, це мінімальний захист від майбутнього нас, який знову спробує вгадати архітектуру по назві сервісу.
