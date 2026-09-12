---
title: Documentation Site
description: Як /docs перетворюється на великий навігаційний documentation surface без дублювання контенту.
status: active
updated: 2026-09-12
kind: ui
---

# Documentation Site

COS documentation має два окремі поняття:

```text
/docs                      canonical knowledge
        ↓
VitePress build             renderer / navigation / search
        ↓
/public/docs                generated static site
```

`public/docs` не є source of truth і не редагується вручну.

## Чому окремий documentation surface

Документація вже перевищує формат одного README. Потрібні:

- багаторівневий sidebar;
- full-text search;
- breadcrumbs/navigation context;
- right-side page outline;
- previous/next navigation;
- deep links на конкретні sections;
- frontmatter metadata;
- edit-on-GitHub link;
- predictable static deployment.

Це UX знань, а не ще одна business application сторінка COS.

## Renderer

Renderer — stable VitePress `1.6.4`, запущений окремими npm scripts.

Він не додається до основного COS frontend dependency graph. Поточний root Vite build продовжує збирати application assets у `public/build`, docs build окремо генерує `public/docs`.

```text
npm run build
    → public/build

npm run docs:build
    → public/docs
```

Так docs не змінюють application bundler і не додають Vue/VitePress runtime у COS application.

## URL model

Production base:

```text
/docs/
```

Clean URLs навмисно вимкнені. Поточний Apache/Phalcon rewrite пропускає існуючі files/directories, тому generated `.html` pages віддаються напряму без втручання application router.

## Sidebar

Sidebar генерується build-time з numeric canonical sections:

```text
00-start
01-product
02-workflows
03-architecture
04-domains
05-runtime
06-ai-agents
07-api-integrations
08-ui
09-development
10-operations
11-decisions
12-reference
```

Новий `.md` у цій структурі автоматично з'являється в navigation. Title береться з frontmatter, fallback — H1/filename.

Legacy/detail directories (`architecture/`, `api/`, `diagnostic/`) залишаються repository reference і можуть бути напряму linked, але не створюють головний sidebar та не входять у local search index.

## Search

Використовується VitePress local full-text search. Зовнішній search backend для першої версії не потрібний.

Search індексує curated canonical documentation. Legacy/detail trees виключені з search, щоб одна й та сама концепція не поверталася в кількох історичних формулюваннях.

## Editing workflow

```text
change code / architecture
        ↓
update canonical /docs page or ADR
        ↓
commit
        ↓
Docs CI builds site
        ↓
static deployment publishes public/docs
```

Generated HTML не комітиться.

## CI rule

Docs CI має запускатися при змінах:

- `docs/**`;
- docs build scripts/config;
- самого workflow.

Build failure означає documentation defect: invalid config, broken Markdown build або інший compile-time problem.

## Future evolution

TARGET, не AS-IS:

- generated API/event/permission/config references з executable manifests;
- link checker/source-code references;
- version selector для tagged COS releases;
- Mermaid/system maps;
- optional authenticated internal sections;
- search ranking по page kind/status;
- automated stale-page detection.

Головне правило не змінюється: **генеруємо reference з коду там, де це можливо; вручну пишемо explanation, workflow, decisions і rationale.**

## Code map

```text
docs/
  index.md
  .vitepress/
    config.mjs
    sidebar.mjs

package.json
.github/workflows/docs.yml
public/docs/             generated, ignored by Git
```
