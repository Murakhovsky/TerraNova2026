# План інтеграції legacy-модулів і підготовки frontend

Дата інвентаризації: 2026-08-26.

Статус виконання станом на 2026-08-28: план завершено для `app/modules`. Каталог `app/modules` і PSR-4 mapping `Modules\\` видалені. Frontend controllers/views перенесені до `Interfaces/Web`, Spatial delivery — до `Interfaces/Api`, CLI tasks — до `Interfaces/Cli`, а 23 Telegram-команди, webhook, rendering і persistence mappings — до `Interfaces/Telegram` та `Infrastructure/Persistence/Phalcon`. Games і його окремі entrypoints видалені. Browser source збирається з `resources/frontend` через Vite multi-entry manifest.

`app/common` більше не має production callers і не входить до Composer/Phalcon autoload. Він залишається лише як фізично неактивний cleanup target; активні реалізації вже розміщені в канонічних шарах. Детальна карта: `legacy-service-migration-ledger.md`.

## 1. Мета

Інтегрувати код з `app/modules`, `app/common`, `public` та інших старих точок входу в канонічну архітектуру `Kernel / Domains / Infrastructure / Interfaces / Bootstrap` без зупинки чинних HTTP, Telegram, CLI та worker-сценаріїв.

Міграція виконується поступово за принципом strangler: нова реалізація з'являється за контрактом, legacy-клас тимчасово делегує їй роботу, після міграції всіх споживачів фасад видаляється.

## 2. Початковий стан (історичний baseline)

У репозиторії одночасно існують:

- нове ядро: `app/Kernel` (76 файлів), `app/Domains` (37), `app/Infrastructure` (35), `app/Interfaces` (7), `app/Bootstrap` (3);
- legacy delivery/business layer: `app/modules` (251 файл);
- спільний legacy-шар: `app/common` (31 файл);
- server-rendered frontend: 15 контролерів, 12 сервісів і 49 PHTML-файлів у `app/modules/frontend`;
- статичні assets та PHP entrypoints у `public`;
- окремий Vue-проєкт `app/modules/Games/PaintIO`, який не входить до головного Vite pipeline;
- Vite pipeline, що наразі збирає тільки spatial viewer з `resources/spatial` у `public/build`.

Найбільші legacy-зони:

| Зона | Файлів | Поточна роль | Ціль |
|---|---:|---|---|
| `modules/TgAdmin` | 97 | Telegram delivery, команди, моделі, rendering | `Interfaces/Telegram` + відповідні Domains + adapters |
| `modules/frontend` | 78 | Web delivery, SQL-oriented services, PHTML | `Interfaces/Web`, `Interfaces/Api`, Domain use cases/read models |
| `modules/Games` | 25 | delivery + game code + окремий Vue app | окремий Game Domain/Interface або ізольований продукт |
| `modules/Users` | 22 | users/company services та дублікати моделей | Identity/Organization Domain + adapters |
| `modules/spatial` | 13 | spatial delivery, processing і persistence | Spatial Domain/Application + Infrastructure + Web/API |
| `modules/cli` | 9 | CLI delivery | `Interfaces/Cli` |
| `modules/economy` | 7 | рання бізнес-зона | уточнити bounded context; потім окремий Domain |
| `common/models` | 19 | Phalcon ActiveRecord | Infrastructure persistence/read models, не Domain model |
| `common/services` | 6 | DB, auth, media, Telegram, events | розділити на порти, adapters та interface-specific services |

## 3. Цільова структура

```text
app/
|-- Kernel/                         reusable mechanisms only
|-- Domains/
|   |-- Sales/
|   |-- Property/
|   |-- Identity/
|   |-- Content/
|   |-- Spatial/
|   `-- Games/                      only if Games remains in this product
|-- Infrastructure/
|   |-- Persistence/MySql/<Domain>/
|   |-- Integration/Telegram/
|   |-- Media/
|   `-- ReadModel/MySql/
|-- Interfaces/
|   |-- Web/Controller/
|   |-- Api/Controller/
|   |-- Telegram/
|   `-- Cli/
|-- Bootstrap/
`-- common/                         inactive cleanup target, outside autoload

resources/
|-- frontend/                       shared browser entrypoints/components/styles
`-- spatial/

public/
|-- index.php                       composition entrypoint
|-- build/                          generated, immutable assets only
|-- uploads/                        runtime data, outside release artifact if possible
`-- img/                            curated static files pending asset migration
```

Правила:

1. `Domains` не залежать від Phalcon, PDO, `Common` або `Modules`.
2. Контролери лише валідовують HTTP input, викликають use case/query і формують response/view model.
3. Запис у БД відбувається через Domain-owned port; SQL/Phalcon ActiveRecord живе в `Infrastructure`.
4. Читання для каталогів і dashboard може йти через спеціалізовані read models, без штучного Domain entity mapping.
5. `Bootstrap` є єдиним місцем складання concrete dependencies.
6. `public` не містить бізнес-логіки, конфігурації із секретами або ручних копій source assets.
7. Longman command classes є Telegram framework adapters: вони можуть використовувати лише canonical `Infrastructure/Persistence/Phalcon` та `Infrastructure/Integration` adapters; залежності на `Infrastructure/Legacy`, `Modules` і `Common` заборонені architecture test-ом.

## 4. Матриця перенесення

| Джерело | Рішення | Пріоритет |
|---|---|---:|
| `modules/frontend/controllers` | переносити маршрут за маршрутом у `Interfaces/Web` або `Interfaces/Api` | P0 |
| `modules/frontend/services/ClientCaseService`, `InboundRequestService` | завершити перенесення orchestration у `Domains/Sales/Application` | P0 |
| `modules/frontend/services/CatalogService` | розділити на Property query/read model і web facade | P0 |
| property submission/moderation/media/presentation services | use cases у Property Domain; media/Telegram реалізації в Infrastructure | P1 |
| content, blog, SEO, n8n | Content Domain/Application + webhook/API Interface | P1 |
| admin, analytics | query services у Infrastructure/ReadModel; команди через відповідні Domain use cases | P1 |
| `common/models/Crm` | зіставити з Sales ports; ActiveRecord залишити тільки adapter/read-model деталлю | P0 |
| `common/models/RealEstate` | Property persistence adapters; бізнес-правила в Property Domain | P1 |
| `common/models/Auth` | Identity persistence adapter | P1 |
| `common/models/Media` | Media infrastructure records або окремий Media Domain лише за наявності бізнес-правил | P1 |
| `common/services/DatabaseService` | заборонити для нового коду; заміняти вузькими repositories/query interfaces | P0 |
| `AuthService` | Identity application port/use cases + session adapter | P1 |
| `MediaStorageService`, `ImageOptimizerService` | `Infrastructure/Media` за контрактами Property/Content | P1 |
| `TelegramAutomationService` | outbound port + `Infrastructure/Integration/Telegram` | P1 |
| `EventService` | перевірити споживачів; замінити Kernel Event/Outbox або видалити | P2 |
| `common/UI` | перенести в `Interfaces/Telegram/Rendering` чи `Interfaces/Web/View`; не залишати cross-channel common layer | P2 |
| `modules/TgAdmin` | спершу delivery-команди, потім моделі; не копіювати дублікати Users models | P2 |
| `modules/Users` | сформувати Identity/Organization context після карти таблиць і сценаріїв | P2 |
| `modules/spatial` | зберегти UI route, винести processing/persistence за портами | P1 |
| `modules/cli` | механічно перенести tasks у `Interfaces/Cli`, bootstrap лишити сумісним | P2 |
| `modules/Games`, `economy` | окреме рішення keep/extract/archive після перевірки runtime-використання | P3 |
| `public/webtools.php`, `webtools.config.php`, `games.php`, `tgAdmin_webhook.php` | перевірити зовнішні виклики; замінити route/controller або CLI; прибрати після deprecation window | P0 |
| `public/js`, `public/css` | перенести source у `resources/frontend`; у `public/build` лишити build output | P1 |
| `public/uploads` | винести в configured storage/volume; заборонити включення runtime uploads у release | P0 |
| root `index.html`, `.htrouter.php`, `.htaccess`, `run` | документувати середовища використання; залишити один підтримуваний шлях запуску на середовище | P1 |
| `LightsailDefaultKey-eu-central-1.pem` | негайно вилучити з репозиторію, відкликати/замінити ключ і перевірити git history | P0 security |

## 5. Послідовність робіт

### Етап 0 — Baseline і запобіжники (1–2 дні)

- Зафіксувати повний список HTTP routes, CLI commands, Telegram webhooks/workers і cron/process entrypoints.
- Додати characterization tests для критичних маршрутів: статус, content type, auth/CSRF, основна форма response.
- Зафіксувати DB-таблиці, до яких звертається кожен legacy service/model.
- Додати архітектурну заборону нових залежностей `Domains -> Common|Modules` та лічильник legacy references.
- Визначити owner і рішення `keep / migrate / extract / delete` для кожного модуля.
- Усунути секретний PEM-файл та перевірити історію репозиторію.

Критерій виходу: є route/entrypoint inventory, regression baseline і нуль нових legacy-залежностей.

### Етап 1 — Розрізати frontend module (3–5 днів)

- Винести реєстрацію маршрутів з великого `Modules/Frontend/Module.php` у route files за зонами: public, property, client-case, content, admin, API/COS.
- Перенести DI factories у `Bootstrap` за доменами; legacy service IDs тимчасово залишити aliases/decorators.
- Зробити `Interfaces/Web/Controller` базовою HTTP межею; прибрати service-locator access із нових контролерів через constructor/factory wiring, де дозволяє Phalcon integration.
- Для кожного endpoint створити явний request DTO і view/response model на межі Interface.

Критерій виходу: новий маршрут можна додати без редагування legacy `Module.php`; чинні URL не змінені.

### Етап 2 — Sales/CRM vertical slice (3–5 днів)

- Завершити `ClientCaseService` і `InboundRequestService`, бо вони вже частково делегують у новий Sales use case.
- Винести решту транзакцій, event emission та tenant scoping у `Domains/Sales/Application`.
- SQL reads винести в `Infrastructure/ReadModel/MySql/Sales`.
- Залишити старі frontend services тонкими compatibility facades, додати deprecation marker і тест еквівалентності.

Критерій виходу: web, API, worker і webhook використовують ті самі Sales use cases; frontend service не виконує SQL або domain transitions.

### Етап 3 — Property + Media vertical slice (1–2 спринти)

- Створити `Domains/Property/{Model,Application,Automation,Bootstrap}` тільки для реальних правил і use cases.
- Визначити порти submission, moderation, catalog mutation, media attachment і presentation.
- Перенести ActiveRecord/SQL у `Infrastructure/Persistence/MySql/Property`, каталог — у read model.
- Перенести file/image operations у `Infrastructure/Media`, Telegram notifications — за outbound port.
- Зберегти PHTML сторінки як delivery templates до готовності нового frontend.

Критерій виходу: Property controllers не залежать від `Common\Services\DatabaseService` чи ActiveRecord.

### Етап 4 — Content, Identity, Spatial (1–2 спринти)

- Content: blog/pages/SEO/n8n ingestion, з чітким поділом command/query.
- Identity: login/register/session/membership/admin users; `organization_id` визначається через authorized context.
- Spatial: processing jobs і assets через Spatial application contracts; controller і viewer лишаються delivery/assets.

Критерій виходу: усі активно використовувані web business flows проходять через application contracts.

### Етап 5 — Telegram, Users, CLI, Games/Economy — виконано

- TgAdmin commands зробити Telegram Interface adapters, а їх бізнес-операції направити до Sales/Property/Identity use cases.
- Усунути дублікати моделей між `TgAdmin` і `Users`; canonical persistence mapping має бути один.
- CLI tasks перенести в `Interfaces/Cli` без зміни команд оператора.
- Для Games і Economy ухвалити ADR: інтегрувати як Domain, винести в окремий deployable або архівувати.

Результат: 23 Telegram-команди реєструються з `Interfaces/Telegram/Command`; моделі й rendering мають канонічні namespace; CLI перенесено; Games видалено. Economy видалено як неактивну й не підкріплену схемою БД функцію. `app/modules` відсутній.

### Етап 6 — Видалення compatibility layer — виконано для modules

- На кожен фасад: пошук усіх callers, production telemetry/deprecation window, видалення alias, code і route.
- Видалити `Common` PSR-4 mapping лише після нульового використання.
- Прибрати generic module routes, якщо всі підтримувані URL описані явно.
- Оновити deployment, runbooks і architecture tests.

Результат: активні entrypoints працюють через `Interfaces`; `Modules\\` і `Common\\` autoload mappings відсутні. `app/common` неактивний і очікує окремого фізичного cleanup.

## 6. Підготовка frontend

### Рекомендований підхід

Не починати з повного SPA rewrite. Спочатку стабілізувати backend contracts і asset pipeline, після цього мігрувати екрани вертикальними slices. PHTML може лишатися SSR shell, а інтерактивні компоненти — підключатися як ES modules/islands.

### Структура frontend

```text
resources/frontend/
|-- entrypoints/       public-site.js, cabinet.js, admin.js, property.js
|-- api/               typed request clients and error normalization
|-- components/        reusable UI components
|-- features/          catalog, property, client-case, auth, content
|-- styles/            tokens, base, components, pages
`-- assets/
```

### Перший frontend backlog

1. Додати Vite multi-entry build поруч зі spatial entrypoint; manifest використовувати для hashed asset URLs.
2. Перенести `public/js/terranova-*` і `public/css/terranova-*` у `resources/frontend`, не змінюючи поведінку.
3. Винести inline scripts/styles із PHTML і заборонити нові inline handlers.
4. Визначити API conventions: `/api/v1`, JSON envelope/error shape, validation errors, pagination/filter/sort, CSRF для session writes, idempotency key для критичних commands.
5. Згенерувати або вручну підтримувати API contract (OpenAPI); frontend не повинен знати DB/Phalcon naming.
6. Запровадити design tokens: color, typography, spacing, radius, breakpoint, z-index; потім shared header/footer/dock.
7. Додати frontend checks: lint/format, unit tests для API/state, Playwright smoke для catalog → property → submit/login.
8. Визначити SSR/SEO межу: blog, landing, catalog/property canonical pages лишаються SSR; cabinet/admin можуть мігрувати до richer client UI.
9. Games/PaintIO видалено за рішенням product owner; він не входить до основного frontend pipeline.

### Порядок міграції екранів

1. Shared layout і asset loading.
2. Публічний catalog/property show (read-only, високий SEO вплив).
3. Auth і cabinet.
4. Property submission/edit/media.
5. Client case/inbox.
6. Admin/content/spatial management.

Для кожного екрана Definition of Done: стабільний API/view model, responsive states, loading/empty/error states, accessibility keyboard/focus, analytics contract, browser smoke test і відсутність прямої залежності UI від legacy route internals.

## 7. Робочий backlog і залежності

| ID | Робота | Залежить від | Результат |
|---|---|---|---|
| MIG-001 | Entry point + route inventory | — | таблиця route → controller → service → tables |
| MIG-002 | Security cleanup PEM | — | rotated key, clean repository/history decision |
| MIG-003 | Legacy dependency fitness test | — | CI не допускає збільшення coupling |
| MIG-004 | Split frontend routes/DI | MIG-001 | керована delivery boundary |
| MIG-005 | Sales ClientCase/Inbound use cases | MIG-003 | перший завершений vertical slice |
| MIG-006 | Property contracts/read models | MIG-001, MIG-003 | backend contract для catalog/submission |
| MIG-007 | Media adapter/storage policy | MIG-006 | storage поза release artifact |
| FE-001 | Vite multi-entry + manifest helper | MIG-004 | єдиний asset pipeline |
| FE-002 | API conventions/OpenAPI baseline | MIG-005, MIG-006 | стабільний frontend/backend контракт |
| FE-003 | Design tokens/shared shell | FE-001 | база для поекранної міграції |
| FE-004 | Catalog/property slice | FE-002, FE-003 | перша production UI міграція |
| MIG-008 | Content/Identity/Spatial slices | MIG-004 | решта активного web backend |
| MIG-009 | Telegram/Users deduplication | MIG-005, MIG-006 | виконано: canonical command/persistence namespaces |
| MIG-010 | Games/Economy ADR | MIG-001 | виконано: Games і неактивний Economy removed |
| MIG-011 | Remove compatibility layer | усі відповідні slices | виконано для `app/modules`; `app/common` має нуль callers |

## 8. Контроль ризиків

- Не змінювати URL, payload або таблицю одночасно в одному migration slice без compatibility adapter.
- Не переносити ActiveRecord class у Domain перейменуванням namespace: спочатку контракт, потім adapter.
- Не будувати спільний `Common` повторно під іншою назвою; shared code допускається лише за стабільною технічною відповідальністю.
- Усі writes мають зберігати tenant isolation, transaction boundary, event/outbox та idempotency вимоги.
- Runtime uploads, generated bundles і secrets мають різні lifecycle та не повинні змішуватися в Git/release image.
- Видалення legacy коду дозволене лише після пошуку callers, regression tests і контрольованого deprecation window.

## 9. Метрики завершення

- 0 production references на `Common\` і `Modules\`.
- 100% активних route/command/webhook entrypoints мають owner і regression test.
- 0 Domain classes залежать від Phalcon/PDO/Common/Modules.
- 0 frontend business services виконують raw SQL після завершення відповідного slice.
- 100% browser source assets збираються з `resources`; `public/build` є відтворюваним output.
- Критичні web flows покриті Playwright smoke tests.
- `app/modules` видалено після досягнення нульових production callers; `app/common` також має нуль callers і виключений з autoload.

## 10. Наступний cleanup

Після окремого підтвердження можна фізично видалити неактивні `app/common` і `app/Infrastructure/Legacy`. До цього вони не входять до autoload і не мають production callers. Подальша frontend-робота має виконуватися лише в `resources/frontend`, `Interfaces/Web/View` та через стабільні `/api/v1` contracts.
