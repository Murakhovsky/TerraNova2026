# WEB V0.15 — COS Visual Language: Calm Technical

WEB V0.15 фіксує єдину візуальну мову COS для Workspace-поверхні. Мета релізу — прибрати теплу бежеву SaaS-естетику, надлишкову картковість і розпливчасту геометрію та замінити їх стабільним enterprise-стилем із чітким характером.

## Канонічний напрям

**Calm Technical / Graphite + Cobalt**

Ключові властивості:

- холодна нейтральна база;
- темна graphite-навігація;
- світлий робочий простір;
- cobalt як єдиний brand signal color;
- семантичні кольори не змішуються з brand accent;
- тонкі чіткі borders замість декоративних shadows;
- щільна інформаційна верстка;
- менші радіуси;
- стримана типографіка;
- мінімум декоративних gradients та glass-ефектів;
- surface hierarchy формується контрастом, alignment та spacing.

## Візуальна конституція

### Color

```text
Workspace      #F5F6F8
Surface        #FFFFFF
Surface subtle #F0F2F5
Ink            #17191C
Muted          #68707B
Border         #DFE3E8
Graphite       #17191D
Cobalt         #5367FF
Cobalt hover   #4052E8
Cobalt soft    #EEF0FF
```

Зелений, жовтий, червоний та синій інформаційний кольори зарезервовані за business semantics: success, warning, danger та info.

### Geometry

```text
Control radius  6px
Panel radius    8px
Overlay radius 10px
Pill           999px тільки для status/tag
```

Великі rounded-card surface не є базовим патерном COS.

### Elevation

Звичайні cards, panels і tables використовують border та мінімальну тінь. Сильна elevation дозволена для overlay-контекстів: modal, drawer, dropdown, command palette.

### Typography

Канонічний stack починається з `Geist`, потім `Inter` і системних sans-serif fallback. Відсутність локального Geist не повинна ламати інтерфейс.

Робочий інтерфейс використовує 13–16px для основної інформації та 24–32px для page-level заголовків. Дрібний текст не використовується для primary business information.

### Density

Workspace має бути information-dense, але не дрібним. Cards не повинні створювати декоративну порожнечу. KPI, tables, filters і operational panels оптимізуються для швидкого scanning.

## Canonical primitives

WEB V0.15 оновлює базові primitives:

- Button;
- Card / Panel;
- KPI;
- Status;
- Input / Select / Textarea;
- Tabs;
- Table;
- Filter Bar;
- Page Header;
- Dialog;
- Alert / Empty / Loading state.

Bootstrap залишається implementation primitive. COS tokens та `tn-*` компоненти залишаються продуктним API.

## Workspace shell

Sidebar є graphite surface. Active navigation використовує restrained cobalt signal. Topbar і workspace content залишаються light-neutral.

Shell не використовує gold як brand color. Green не використовується для profile/avatar branding, оскільки зелений зарезервований для positive semantics.

## Адаптивність

Calm Technical не означає просто desktop shrink.

- desktop: information density + structural borders;
- tablet: collapsed navigation без зміни семантики;
- mobile: більші touch targets, спрощений shell, збережена cobalt/graphite hierarchy;
- overlay elements можуть використовувати сильнішу elevation, але не змінюють загальну visual language.

## Definition of Done для наступних компонентів

Новий canonical component повинен успадковувати:

1. color tokens;
2. radius scale;
3. border/elevation rules;
4. typography hierarchy;
5. responsive/touch states;
6. hover/focus/disabled/loading/error states;
7. semantic color discipline;
8. Workspace density rules.

Feature CSS не повинен повертати локальні beige/gold design systems без окремого surface-level обґрунтування.
