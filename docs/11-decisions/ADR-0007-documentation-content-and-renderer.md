---
title: ADR-0007 — Documentation content і renderer розділені
description: Рішення відокремити документаційний контент і generated reference від disposable renderer output.
status: accepted
updated: 2026-09-16
kind: decision
---

# Контекст

COS documentation має бути зручною як великий technical documentation site, але canonical knowledge живе поруч із кодом у `/docs`. Перенесення content у CMS, PHP views або окремий frontend project створило б другу source of truth.

Основний application frontend має власний Vite build, а documentation website використовує VitePress 1.6.4 як окремий static renderer. Змішувати ці runtime/dependency graphs без потреби немає сенсу.

# Рішення

**Canonical content залишається в `/docs`; documentation website є окремим static rendering build цього самого content.**

```text
/docs
  ↓
VitePress 1.6.4
  ↓
/public/docs
```

Main application build не імпортує VitePress. Docs runner запускається окремими scripts і генерує static artifact.

Водночас потрібно розрізняти два типи generated content:

```text
docs/12-reference/*.md   → generated reference, tracked у git і перевіряється drift gate
public/docs              → disposable rendered site artifact, не є source of truth
```

Navigation будується з canonical numeric sections і audience-specific sections.

# Обґрунтування

- одна content source;
- docs-as-code поруч із implementation;
- готовий UX technical documentation: sidebar, outline, search, deep links;
- static deployment без PHP Markdown renderer;
- docs tooling не змінює application bundle;
- CI може окремо перевіряти generation, contracts і rendering;
- generated executable reference може бути атомарно закомічений із code change.

# Розглянуті альтернативи

## Custom Markdown renderer у PHP

Відхилено: довелося б самостійно підтримувати Markdown parsing, navigation, search, anchors, highlighting і security edge cases.

## Content copy у CMS/database

Відхилено: створює synchronization problem між repo documentation та published site.

## VitePress усередині основного frontend bundle

Відхилено: application і documentation мають різні задачі та dependency lifecycle; documentation build не потребує змішування їх у один runtime.

# Наслідки

Позитивні:

- `/docs` лишається канонічним content root;
- `public/docs` disposable/rebuildable;
- documentation UX може еволюціонувати незалежно від COS UI;
- build failure легко перевіряти в CI;
- generated reference має окремий committed-sync contract.

Обмеження:

- build runner потребує Node/npm access;
- publication artifact має входити в deployment pipeline;
- приватні documentation sections потребуватимуть окремого access рішення, якщо з’являться.

# Сумісність і міграція

Існуючі canonical `.md` не переносяться в іншу систему.

`docs/12-reference` генерується з current checkout і **комітиться**, якщо generator змінює tracked reference. `public/docs` не редагується і не використовується як canonical input.

Це уточнює початкову формулу ADR: «generated output не комітиться» стосується rendered website artifact, а не generated reference corpus.

# Перевірка

- `npm run docs:generate` оновлює `docs/12-reference`;
- committed-reference drift gate має бути чистим;
- `npm run docs:generate:check` підтверджує exact generated output;
- `npm run docs:check` перевіряє content/contracts/processes;
- `npm run docs:build` генерує `public/docs`;
- application build лишається окремим flow.

# Пов’язані матеріали

- `docs/08-ui/documentation-site.md`
- `docs/10-operations/documentation-build.md`
- `docs/09-development/documentation-rules.md`
- `.github/workflows/docs.yml`
- `package.json`
