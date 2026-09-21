---
title: Візуальна конституція COS v1
description: Канонічні принципи візуальної мови Company Operating System для всіх Domains, themes і business surfaces.
status: active
updated: 2026-09-21
kind: architecture
---

# Візуальна конституція COS v1

## 1. Призначення

COS є Company Operating System для бізнесу.

Візуальна система повинна допомагати користувачу швидко відповісти на питання:

1. Що відбувається зараз?
2. Де гроші?
3. Де проблема або ризик?
4. Хто відповідальний?
5. Яка наступна дія?
6. Що змінилося?
7. Куди рухається бізнес?

Інтерфейс не повинен конкурувати з бізнес-інформацією.

Основна формула:

```text
Control
+
Money
+
Movement
=
COS visual experience
```

## 2. Характер COS

COS має виглядати:

- точним;
- спокійним;
- технологічним;
- дорогим через якість, а не декоративність;
- інформаційно щільним;
- професійним;
- живим, але не шумним;
- сучасним без короткоживучих трендових ефектів.

COS не повинен виглядати як:

- типовий CRM template;
- Bootstrap admin theme;
- crypto dashboard;
- consumer social application;
- neon cyberpunk interface;
- beige lifestyle SaaS;
- набір незалежних Domain dashboards.

Робоча назва напряму:

> **Calm Technical**

## 3. Десять канонічних принципів

### 3.1 Спокійність

Декоративний шум мінімізується.

Колір, shadow, animation і accent існують лише тоді, коли допомагають ієрархії, стану або дії.

### 3.2 Точність

Основний характер створюють:

- grid;
- alignment;
- spacing;
- borders;
- type hierarchy;
- consistent geometry.

Компоненти не повинні "плавати" у випадкових картках.

### 3.3 Інформаційна щільність

COS є професійним робочим інструментом.

Whitespace використовується для ієрархії, а не як самоціль.

Потрібно підтримувати щонайменше:

- comfortable density;
- compact density.

### 3.4 Фінансова орієнтація

Гроші, KPI, conversion, margin, forecast, target і variance є first-class visual data.

Financial information не оформлюється як звичайний body text.

### 3.5 Операційність

Primary action і next action повинні бути помітними без пошуку.

Користувач має розуміти, що робити далі.

### 3.6 Преміальність

Premium означає:

- чисту типографіку;
- точну геометрію;
- контрольований контраст;
- якісний spacing;
- передбачувані interaction states;
- стриманий motion;
- відсутність візуального сміття.

Premium не означає gold gradients, glass everywhere або великі тіні.

### 3.7 Технологічність без developer-only мислення

COS може виглядати технологічно, але не повинен вимагати від керівника мислити як програміст.

Технічні деталі відображаються лише там, де це частина use case.

### 3.8 Нейтральність за замовчуванням

Нейтральні surfaces і typography складають більшу частину UI.

Колір використовується як signal.

### 3.9 Відчуття живої системи

Realtime, activity, async operations та AI повинні створювати відчуття живої системи.

Живість передається через:

- актуальні state indicators;
- timestamps;
- progress;
- activity;
- restrained transitions;
- presence/update signals.

Не через постійні декоративні animation.

### 3.10 Єдиний продукт

Sales, Finance, HR, Property, Service, Procurement, Documents та інші Domains використовують одну UI мову.

Domain змінює бізнес-зміст, а не базову візуальну граматику.

## 4. Ієрархія візуальної системи

```text
Foundation values
    ↓
Semantic tokens
    ↓
Themes
    ↓
Primitive components
    ↓
Interaction / Forms / Data / Workspace components
    ↓
Business UI patterns
    ↓
Domain surfaces
```

Domain surface не має права звертатися напряму до foundation palette, якщо існує semantic token.

## 5. Теми оформлення

Канонічна продуктова модель:

```text
COS Visual System

Brand identity
└── Origin

Appearance preferences
├── Light
└── Dark
```

### Origin як фірмовий режим

Origin є signature appearance COS.

Його характер:

```text
graphite chrome
+
cool mineral workspace
+
precise light surfaces
+
cobalt interaction signal
```

Origin використовується за замовчуванням у:

- product demos;
- screenshots;
- sales materials;
- documentation examples;
- presentations;
- marketing visuals.

Origin не є dark mode.

### Light як світлий режим

Повністю світлий професійний workspace.

Призначення:

- тривала денна робота;
- максимальна нейтральність;
- світла navigation hierarchy;
- офісне середовище.

### Dark як темний режим

Повноцінний dark workspace.

Темний режим не створюється автоматичним invert.

Кожен semantic state повинен мати власні dark values.

### System як системне налаштування

System може існувати як user preference, що вибирає Light або Dark за налаштуванням ОС.

System не є четвертою brand theme.

## 6. Колірна філософія

### Фірмовий акцент

Канонічний напрям accent:

- cobalt / electric indigo.

Accent використовується для:

- primary interaction;
- selection;
- focus;
- active navigation;
- controlled brand moments.

Accent не використовується як:

- success;
- warning;
- danger;
- financial growth;
- arbitrary decoration.

### Семантичні кольори

Семантика незалежна від brand:

- positive;
- warning;
- danger;
- info;
- neutral.

Status ніколи не передається лише кольором.

Потрібен text, icon, shape або інший redundant signal.

### Ієрархія поверхонь

Більшість інтерфейсу складається з neutral hierarchy:

```text
Canvas
↓
Surface
↓
Subtle
↓
Raised
↓
Floating
↓
Overlay
```

Surface hierarchy повинна читатися навіть без shadow.

## 7. Типографіка

Типографіка має передавати:

- точність;
- високу інформаційну щільність;
- сучасність;
- надійність.

Основний UI font повинен:

- якісно підтримувати українську й латиницю;
- мати добру читабельність при 12-16 px;
- мати якісні цифри;
- підтримувати variable або достатній набір weights;
- не бути декоративним.

Кандидати для Style Lab:

- Geist;
- Inter;
- IBM Plex Sans.

Поки font не затверджений остаточно, semantic type scale важливіша за конкретну family.

### Типографіка чисел

Для financial/data surfaces:

```css
font-variant-numeric: tabular-nums;
```

де це покращує vertical alignment.

Числа повинні мати окрему ієрархію:

- primary metric;
- delta;
- comparison;
- target;
- forecast;
- secondary metadata.

## 8. Геометрія

Канонічний напрям:

- xs: 4 px;
- sm: 6 px;
- md: 8 px;
- lg: 10 px;
- pill: лише tags, badges, toggles та спеціальні compact controls.

Великі rounded rectangles не є основною мовою COS.

Геометрія повинна виглядати точніше, ніж типовий consumer SaaS.

## 9. Spacing і rhythm

Spacing використовує token scale.

Заборонено створювати випадкові локальні 13/17/29 px лише для "візуального балансу", якщо use case можна вирішити canonical spacing.

Основні правила:

- related items closer;
- groups clearly separated;
- page sections мають стабільний vertical rhythm;
- compact mode змінює geometry через density tokens, а не через локальні overrides.

## 10. Borders і shadows

Основна структурна глибина:

```text
surface contrast
+
border
+
spacing
```

Shadow резервується для:

- overlay;
- dropdown;
- modal;
- drawer;
- floating action surface;
- elevated transient UI.

Постійні dashboard panels не повинні виглядати як стопка карток, що висить над canvas.

## 11. Cards і panels

Card не є універсальним layout primitive.

Card використовується, коли блок справді є окремою інформаційною одиницею.

Для великих operational surfaces перевага надається:

- sections;
- separators;
- rails;
- tables;
- grouped panels;
- structured canvas.

Мета: менше "card inside card inside card".

## 12. Дії

У кожному контексті має існувати зрозуміла action hierarchy:

1. primary;
2. secondary;
3. contextual;
4. destructive.

Primary action не повинен конкурувати з кількома однаково яскравими кнопками.

Destructive action не використовує brand accent.

UIAction contract є semantic source, але visual placement регулюється canonical UX patterns.

## 13. Форми

Forms повинні бути:

- компактними;
- чіткими;
- доступними;
- без декоративних контейнерів навколо кожного field;
- із видимими error/help/required states.

Label не замінюється placeholder.

Validation повинна бути зрозумілою без reliance only on color.

## 14. Data і tables

COS є data-heavy product.

Canonical DataGrid повинен підтримувати:

- сильну column alignment;
- readable row density;
- numeric alignment;
- hover/selection state;
- sort/filter state;
- bulk actions;
- empty/loading/error;
- mobile card mode.

Data table не повинна перетворюватися на декоративний dashboard.

## 15. Візуальна мова фінансів

Financial UI є окремою частиною COS identity.

Канонічні поняття:

- Money;
- Delta;
- Trend;
- Goal;
- Forecast;
- Actual;
- Variance;
- Margin;
- Risk;
- Confidence.

Правила:

- currency format consistent per locale/context;
- positive/negative delta має знак і text/icon signal;
- forecast візуально відрізняється від actual;
- target не змішується з current value;
- risk не маскується brand accent;
- великі KPI не повинні займати непропорційно багато viewport.

## 16. Візуальна мова бізнес-сутностей

Business entity повинна мати стабільну anatomy незалежно від Domain:

```text
Identity
Status / Stage
Primary value
Responsible
Key metadata
Primary action
Next action
Relations
Activity
History
```

Майбутні canonical patterns:

- EntityHeader;
- EntitySummary;
- EntityCard;
- EntityListItem;
- RelationList;
- Stage;
- Timeline;
- ActivityItem;
- NextAction.

## 17. Робочий простір

Workspace Shell є незмінною cross-domain оболонкою.

Domain не створює окремий:

- global sidebar;
- global topbar;
- command palette;
- mobile navigation;
- account/navigation chrome.

Workspace має підтримувати:

- entity context;
- main content;
- primary/secondary actions;
- tabs/navigation;
- context rail;
- activity;
- documents;
- AI context.

## 18. Візуальна мова AI

AI є capability COS, а не окремою декоративною темою.

AI може мати restrained accent:

```text
cobalt
→
subtle violet
```

лише для:

- agent identity;
- recommendation surface;
- reasoning/result boundary;
- AI-originated action;
- active processing state.

AI content не повинно світитися неоном або мати glow за замовчуванням.

Human і AI actions використовують один UIAction permission contract.

## 19. Рух та анімація

Motion пояснює state change.

Канонічний напрям:

- hover: близько 120 ms;
- control state: 120-180 ms;
- modal: близько 180 ms;
- drawer: близько 220 ms;
- complex transition: лише за реальної потреби.

Обов'язково підтримується `prefers-reduced-motion`.

Заборонені без окремого use case:

- bounce;
- decorative infinite animation;
- parallax у operational workspace;
- animation, що затримує доступ до дії.

## 20. Іконографіка

Іконка:

- допомагає scanability;
- не замінює critical text;
- має стабільний stroke/weight style;
- не змішує кілька несумісних icon families на одній surface.

Icon-only action потребує accessible label і, де доречно, tooltip.

## 21. Доступність

Цільовий мінімум: WCAG 2.2 AA для стандартного product UI.

Обов'язкові:

- keyboard navigation;
- visible focus;
- sufficient contrast;
- semantic markup;
- touch target;
- screen reader labels;
- reduced motion;
- status not by color only.

Accessibility не є окремим "режимом дизайну". Вона входить у Definition of Done кожного компонента.

## 22. Принципи адаптивності

Адаптивність означає зміну поведінки, а не просте shrink desktop.

Типові transitions:

```text
Data table      → entity list/cards
Context rail    → stacked sections
Drawer          → full-screen drawer
Action bar      → sticky bottom actions
Sidebar         → mobile drawer
Global nav      → bottom navigation / command access
```

Той самий server payload і business contract використовуються на desktop і mobile.

## 23. Content і localization

Component layout повинен витримувати:

- українську;
- англійську;
- польську;
- інші майбутні locales.

Не можна будувати critical controls на fixed width, що працює лише з коротким English label.

Truncation використовується лише там, де існує спосіб побачити повне значення.

## 24. Що заборонено у canonical COS style

За замовчуванням не використовуються:

- beige/cream як базовий Workspace характер;
- gold як primary brand accent;
- великі gradient fills;
- масовий glassmorphism;
- glow;
- neon;
- великі rounded cards;
- shadow на кожному panel;
- різні UI styles для різних Domains;
- довільні raw colors у Domain CSS;
- decorative animation без UX purpose.

## 25. Канонічні еталонні поверхні

Visual language перевіряється не на ізольованому Button.

Мінімальні reference surfaces:

1. Design System Catalog: `/dev/ui`;
2. Workspace reference: `/dev/workspace`;
3. Executive/Company Home;
4. dense operational DataGrid;
5. entity workspace;
6. COS Control/AI/operations surface.

До появи всіх production screens перші дві dev surfaces є основним visual laboratory.

## 26. Критерії оцінки стилістичного варіанта

Кожен Style Lab candidate оцінюється за однаковими критеріями:

- clarity;
- information density;
- scan speed;
- action discoverability;
- perceived premium quality;
- perceived trust;
- financial/data readability;
- long-session comfort;
- mobile viability;
- dark/light parity;
- accessibility;
- implementation cost;
- consistency across Domains.

Стиль не приймається лише тому, що hero screenshot виглядає ефектно.

## 27. Критерії завершення PHASE 1

Visual Constitution вважається впровадженою, якщо:

- документ є canonical source;
- principles перевіряються architecture gate на рівні ключових контрактів;
- Domains не створюють власний global design system;
- semantic token ownership зафіксований;
- Theme model `Origin / Light / Dark` визначений концептуально;
- financial, entity, AI, motion, accessibility і responsive principles зафіксовані;
- наступний Style Lab може змінювати appearance без зміни foundation;
- усі наступні canonical components мають посилатися на цю Constitution.

## 28. Наступний крок

Після PHASE 0 і PHASE 1:

```text
PHASE 2
Style Lab
Origin A / B / C
↓
PHASE 3+
Theme і canonical component visual implementation
```

До PHASE 2 не потрібно "остаточно вибирати красивий колір". Спочатку зафіксовано правила гри, щоб експерименти були швидкими й оборотними.
