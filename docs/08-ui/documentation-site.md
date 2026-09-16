---
title: Сайт документації
description: Як каталог docs перетворюється на навігаційний сайт документації без дублювання джерела правди.
status: active
updated: 2026-09-16
kind: ui
---

# Сайт документації

```text
main code + docs
      ↓
reference generation
      ↓
VitePress build
      ↓
public/docs
```

`public/docs` є згенерованим статичним артефактом і не редагується вручну.

## Взаємодія з користувачем

Сайт документації надає:

- бічну навігацію;
- локальний повнотекстовий пошук;
- структуру поточної сторінки;
- переходи вперед і назад;
- прямі посилання на розділи;
- технічні метадані сторінки там, де вони доречні;
- перехід до редагування в GitHub;
- інтерактивні карти й діаграми системи.

Бізнесові та інтеграторські сторінки не повинні показувати внутрішні технічні метадані лише тому, що VitePress уміє це зробити.

## Збірка

VitePress `1.6.4` запускається окремо від основної збірки Vite-застосунку.

```text
npm run build       → public/build
npm run docs:build  → public/docs
```

## Модель гілки

Окремої моделі «документація в одній гілці, код в іншій» більше немає. `main` містить code, tests, docs, generators і deployment metadata.

Кожна збірка документації описує той самий checkout, з якого вона запущена.

## Згенерований довідник

Modules, capabilities, routes, permissions, use cases, events і commands генеруються з актуального `main` перед build.

Такі факти не слід дублювати вручну в пояснювальних сторінках.

## Процес редагування

```text
change code / architecture in main
        ↓
update narrative docs when meaning changed
        ↓
generate reference
        ↓
Docs CI validates same commit
        ↓
VitePress build / deployment
```

## Карта коду

```text
docs/
  index.md
  03-architecture/system-map.md
  .vitepress/
    config.mjs
    sidebar.mjs
    check.mjs
    generate-*.php
    theme/
package.json
.github/workflows/docs.yml
public/docs/  generated
```

## Інваріант

> Пояснювальна документація описує сенс. Згенерований довідник фіксує факти, які може довести код. Статична збірка лише публікує обидва шари й не стає третім джерелом правди.
