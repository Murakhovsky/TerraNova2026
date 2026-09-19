# Аудит меж Domains

## Критерій Domain

Каталог `Domains/<Name>` потрібен лише тоді, коли область має власну бізнесову мову, стан і життєвий цикл, правила або інваріанти та use cases, які не залежать від конкретного HTTP, Telegram, CRM або storage-каналу.

Технічний канал, SQL-проєкція, транспорт повідомлень, файлове сховище, telemetry або SDK самі по собі не є Domain. Вони розміщуються у Platform/Infrastructure згідно з ownership.

## Рішення

| Область | Рішення | Власна бізнесова відповідальність |
|---|---|---|
| `Sales` | залишити, еталонний COS Domain | Lead, Opportunity, pipeline, activity, matching, follow-up та Sales automation |
| `Diagnostic` | залишити | methodology packs, sessions, evidence, findings, recommendations і traceability |
| `Property` | залишити | canonical real-estate asset registry, identity, inventory, listing/publication, catalog та presentation |
| `RealEstate` | додати як окремий Domain | brokerage case, mandate, showing, offer та інший брокерський lifecycle поверх Property references |
| `Service` | V1 skeleton | service case, request, ticket, SLA, assignment, resolution |
| `Finance` | V1 skeleton | account, transaction, invoice, payment, budget, expense, revenue |
| `Procurement` | V1 skeleton | supplier, purchase request, quote, order, delivery |
| `HR` | V1 skeleton | employee, position, candidate, recruitment, onboarding, performance |
| `Construction` | V1 skeleton | project, site, object, estimate, contractor, work, material, milestone, inspection |
| `Content` | залишити | керований контент, revision, scheduling, publication та SEO lifecycle |
| `Identity` | залишити | account, organization membership, role, authentication та access lifecycle |
| `Spatial` | залишити | scene, version, asset, capture, hotspot, processing та publication lifecycle |
| `Documents` | Platform capability, не Domain | активний generic runtime для document/file/template/version/signature/relation/permission; бізнесові правила документа лишаються у відповідних Domains |
| `Analytics` | не Domain | технічна telemetry/read projection у Platform/Infrastructure |
| `Notification` | не Domain | delivery capability у Platform, провайдери в Infrastructure |

Наявність каталогу не означає готовий runtime: skeleton Domains мають `module.php`, але вимкнені за замовчуванням і не мають runtime contributions.

## Property і Real Estate

`Property` є єдиним source of truth для фізичного real-estate asset, його identity, inventory, listing/publication, catalog і presentation. `RealEstate` не створює альтернативних property tables і не переписує вже працюючі catalog/objects/presentations.

Real Estate може зберігати власний брокерський стан та посилатися на `propertyId`, але cross-domain write виконується лише через контракт власника Property.

## Cross-domain правило

Domain не пише напряму в таблиці іншого Domain. Він викликає порт власника. Cross-domain читання дозволене тимчасово лише в окремому read-model, поки не з'явиться projection або API власника.

## Наслідки для нової розробки

- не створювати `Domains/Notification`, `Domains/Analytics`, `Domains/Media`, `Domains/Email` або `Domains/Telegram` без нової предметної моделі;
- зовнішні провайдери розміщувати в `Infrastructure/Integration`;
- технічні shared capabilities на кшталт Documents розміщувати у `Platform`;
- бізнесовий порт оголошує власник відповідальності;
- delivery-рівень викликає Application use case, а не Infrastructure repository.
