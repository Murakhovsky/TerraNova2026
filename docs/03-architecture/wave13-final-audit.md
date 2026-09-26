---
title: Wave 13 — Фінальний аудит
description: Фінальна класифікація production visual ownership після VR-001…VR-047 і debt closure.
status: closed
updated: 2026-09-27
kind: architecture
---

# Wave 13 — Фінальний аудит

Wave 13 закрита production ownership contract, а не декоративною кількістю переписаних файлів.

## Фінальний результат

- VR-001…VR-047: DONE.
- Production Web pages: canonical Symfony/Twig Experience Platform.
- Production page-level PHTML: **0**.
- Legacy `PhtmlRenderer`: **0** у Web runtime.
- Generic/page Vite entrypoints: **0**.
- Canonical browser runtime: AssetMapper / ImportMap / Stimulus / Turbo.
- Specialized Vite runtime: тільки `frontend/spatial/spatial-viewer.js`.
- Public Property intake: active canonical CommandBus write path.

## Єдиний PHTML виняток

`app/Interfaces/Web/View/property/pdf.phtml` лишається не Web page, а service-level print template для Dompdf.

Він не володіє HTTP page rendering, не створює browser runtime і не є дозволом на нові PHTML pages.

## Specialized Spatial island

Spatial viewer лишається окремим Vite/Three.js build island через native JS dependencies і decoder assets. Outer shell, navigation, page composition та presentation state належать Symfony/Twig.

## Заборонено після closure

1. Новий page-level PHTML.
2. Повернення `PhtmlRenderer` у Web Controller.
3. Новий generic/page Vite entrypoint.
4. `tn-*` markup у canonical Twig production templates.
5. Public Property submit placeholder замість Application Command.
6. Spatial business/navigation shell усередині JS island.

## Release gate

Merge дозволений лише коли Wave 13 final audit, Symfony container/Twig lint, Vite build, canonical runtime tests і browser quality/accessibility gates green.

Наступні visual зміни є новою хвилею поверх canonical Experience Platform.
