---
title: Wave 13 — Фінальний аудит
description: Фінальна класифікація production visual ownership після VR-001…VR-047.
status: closed
updated: 2026-09-26
kind: architecture
---

# Wave 13 — Фінальний аудит

Wave 13 закриває visual migration не кількістю переписаних файлів, а ownership contract.

## Результат

- VR-001…VR-047: DONE.
- Production families 3–11: canonical Symfony/Twig Experience Platform.
- Dead historical homepage `app/Interfaces/Web/View/index/index.phtml`: deleted.
- New page-level PHTML: forbidden by executable whitelist.
- Retired page-specific Vite source entrypoints: forbidden by executable whitelist.

## PHTML, який свідомо лишився

### Канонічні surfaces входу

- `auth/login.phtml`
- `auth/register.phtml`

### Спеціалізований runtime

- `diagnostic_report/show.phtml`
- `property/presentation.phtml`
- `property/pdf.phtml`
- `spatial/edit.phtml`
- `spatial/scene.phtml`
- `error/failure.phtml`

### Не page surfaces

- `components/**` — shared compatibility primitives;
- `shared/**` — shared compatibility partials;
- `app/Interfaces/Web/View/index.phtml` — compatibility layout для whitelist surfaces.

Цей список закритий. Новий page-level PHTML вимагає окремого архітектурного рішення, а не тихого повернення старого renderer.

## Вихідні точки клієнтського коду

Після Wave 13 дозволені:

- `cos-ui-runtime.js`;
- `public-surface.js`;
- `terranova-copy.js`;
- `terranova-interface.js`;
- `terranova-media-manager.js`;
- `terranova-spatial-admin.js`.

Spatial viewer живе як specialized source `frontend/spatial/spatial-viewer.js`, а не як generic page entrypoint.

## Згенерований результат збірки

`public/build/**` є generated output і не визначає ownership. CI виконує `npm run build` перед frontend/runtime gates, тому source of truth — `vite.config.js` + `frontend/**`. Stale historical hashes у робочому tree не мають права відновлювати source entrypoint або route ownership.

## Критерії випуску

Merge у `main` дозволений лише через PR після:

1. Wave 13 Visual System = green;
2. WEB V0.14 build/frontend gates = green;
3. Symfony Canonical Runtime = green для зміненого runtime;
4. final audit = green.

Після merge Wave 13 вважається закритим. Наступні visual зміни починаються вже як нова хвиля поверх canonical Experience Platform.
