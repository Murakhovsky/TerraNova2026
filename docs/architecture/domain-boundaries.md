# Аудит меж Domains — 2026-08-29

## Критерій Domain

Каталог `Domains/<Name>` потрібен лише тоді, коли область має власну бізнесову мову, стан і життєвий цикл, правила/інваріанти та use cases, які не залежать від конкретного HTTP, Telegram, CRM або storage-каналу.

Технічний канал, SQL-проєкція, транспорт повідомлень, файлове сховище, telemetry або SDK самі по собі не є Domain. Вони розміщуються в `Infrastructure` і реалізують вузькі порти того Domain, який ними користується.

## Рішення

| Область | Рішення | Власна бізнесова відповідальність |
|---|---|---|
| `Sales` | залишити, еталонний COS Domain | Lead, ClientCase/Deal, pipeline, activities, matching, follow-up, Sales automation та CRM translation |
| `Property` | залишити | об'єкт, submission, moderation, publication та presentation lifecycle |
| `Content` | залишити | керований контент, revision, scheduling, publication та SEO lifecycle |
| `Identity` | залишити | account, organization membership, role, authentication та access lifecycle |
| `Spatial` | залишити | scene, version, asset, capture, hotspot, processing та publication lifecycle |
| `Analytics` | прибрати як Domain | поточна реалізація є технічною funnel/telemetry проєкцією; перенесена до `Infrastructure/Platform/Analytics`, споживчий порт належить Property |
| `Notification` | прибрати як Domain | Telegram є delivery adapter; account linking належить Identity, Property notifications — порту Property, outbox/delivery — Infrastructure |

Поточний дозволений набір каталогів `app/Domains`: `Content`, `Identity`, `Property`, `Sales`, `Spatial`. Додавання нового каталогу вимагає окремого bounded-context рішення та оновлення architecture test.

## Cross-domain правило

Domain не пише напряму в таблиці іншого Domain. Він викликає порт власника. Cross-domain читання дозволене тимчасово лише в окремому read-model, поки не з'явиться projection або API власника.

Не кожен Domain зобов'язаний одразу мати COS automation. `DomainModuleInterface` реалізується тоді, коли область оголошує події, правила/Agent, Actions і Policies для циклу Kernel. Наразі повний модуль має `Sales`.

## Наслідки для нової розробки

- не створювати `Domains/Notification`, `Domains/Analytics`, `Domains/Media`, `Domains/Email` або `Domains/Telegram` без нової предметної моделі;
- зовнішні провайдери розміщувати в `Infrastructure/Integration`;
- технічні shared stores і telemetry розміщувати в `Infrastructure/Platform`;
- бізнесовий порт оголошує той Domain, якому потрібна операція;
- delivery-рівень викликає Application use case, а не Infrastructure repository.

