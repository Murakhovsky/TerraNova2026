# Аудит меж Domains — 2026-08-29

## Критерій Domain

Каталог `Domains/<Name>` потрібен лише тоді, коли область має власну бізнесову мову, стан і життєвий цикл, правила/інваріанти та use cases, які не залежать від конкретного HTTP, Telegram, CRM або storage-каналу.

Технічний канал, SQL-проєкція, транспорт повідомлень, файлове сховище, telemetry або SDK самі по собі не є Domain. Вони розміщуються в `Infrastructure` і реалізують вузькі порти того Domain, який ними користується.

## Рішення

| Область | Рішення | Власна бізнесова відповідальність |
|---|---|---|
| `Sales` | залишити, еталонний COS Domain | Lead, ClientCase/Deal, pipeline, activities, matching, follow-up, Sales automation та CRM translation |
| `Diagnostic` | залишити, універсальний diagnostic engine | versioned methodology packs, sessions, evidence, facts/metrics/assessments, findings, hypotheses, recommendations і traceability |
| `Property` | залишити | об'єкт, submission, moderation, publication та presentation lifecycle |
| `Content` | залишити | керований контент, revision, scheduling, publication та SEO lifecycle |
| `Identity` | залишити | account, organization membership, role, authentication та access lifecycle |
| `Spatial` | залишити | scene, version, asset, capture, hotspot, processing та publication lifecycle |
| `Analytics` | прибрати як Domain | поточна реалізація є технічною funnel/telemetry проєкцією; перенесена до `Infrastructure/Platform/Analytics`, споживчий порт належить Property |
| `Notification` | прибрати як Domain | Telegram є delivery adapter; account linking належить Identity, Property notifications — порту Property, outbox/delivery — Infrastructure |

Поточний дозволений набір каталогів `app/Domains`: `Content`, `Diagnostic`, `Identity`, `Property`, `Sales`, `Spatial`. `Diagnostic` не залежить від моделі цільового Domain: Sales, Finance, Operations, HR або Marketing описуються як target/pack. Додавання нового каталогу вимагає окремого bounded-context рішення та оновлення architecture test.

`Diagnostic` зберігає повну versioned methodology всередині lifecycle pack, а session фіксує її id/version і materializes deterministic assessments/findings із наскрізною evidence traceability. Таблиці `diagnostic_*` належать лише цьому Domain; target domains не пишуть у них напряму й запускають діагностику через Application use cases.

## Cross-domain правило

Domain не пише напряму в таблиці іншого Domain. Він викликає порт власника. Cross-domain читання дозволене тимчасово лише в окремому read-model, поки не з'явиться projection або API власника.

Не кожен Domain зобов'язаний одразу мати COS automation. `DomainModuleInterface` реалізується тоді, коли область оголошує події, правила/Agent, Actions і Policies для циклу Kernel. Наразі повний модуль має `Sales`.

## Наслідки для нової розробки

- не створювати `Domains/Notification`, `Domains/Analytics`, `Domains/Media`, `Domains/Email` або `Domains/Telegram` без нової предметної моделі;
- зовнішні провайдери розміщувати в `Infrastructure/Integration`;
- технічні shared stores і telemetry розміщувати в `Infrastructure/Platform`;
- бізнесовий порт оголошує той Domain, якому потрібна операція;
- delivery-рівень викликає Application use case, а не Infrastructure repository.
