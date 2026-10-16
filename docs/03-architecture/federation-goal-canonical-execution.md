---
title: Міграція виконання Goals на Platform Workflow
description: Контрольований перехід нових федеративних Goals на єдині Run і Step без подвійного виконання Actions.
status: proposed
updated: 2026-10-15
kind: architecture
---

# Контрольований перехід Federation Goals на Platform Workflow

## Призначення

Цей інкремент переносить **нові, явно дозволені** Federation Goal Plans з власного технічного `FederationSequentialOrchestrator` на канонічне сховище `cos_workflow_runs/steps` через `WorkflowExecutionStoreInterface`. Він не переносить автоматично старі записи `cos_federation_runs/steps`, не запускає черги і не змінює Engineering state machine.

Збережено бізнес-власність `Goal`, `GoalSpecification`, `Plan` і `OutcomeEvaluation`. Новий `FederationCanonicalWorkflowBridge` є **тонким адаптером** процесу, а не другим engine: його SQL лише читає й блокує рядки для унеможливлення гонок, усі технічні мутації здійснює канонічний Workflow Store.

## Увімкнення і правила

Для нового маршруту потрібні всі умови: модуль `federation` активний у tenant; власник Goal автентифікований як manager; immutable Plan затверджений незалежною людиною; Action-провайдери опубліковані та активні; у процесі є тільки кроки `side_effect_level=external`, які виконуються через зареєстровані канонічні Action.

Створення нових canonical Run **вимкнено за замовчуванням**. Оператор інфраструктури може обрати рівно один тестовий tenant за допомогою `COS_FEDERATION_CANONICAL_ORGANIZATION=<organization_id>`. Це не HTTP-параметр і не UI-дозвіл. Прив'язка кожного створеного Run зберігається в `cos_workflow_runs`; вимкнення налаштування не повертає такий Run до legacy.

Перед увімкненням необхідно окремо підтвердити MySQL acceptance, повний Action worker flow, shadow parity, Approval/Policy та recovery. Налаштування не слід використовувати на production tenant без цих доказів.

## Гарантії від дублювання

1. На старті Plan рядок блокується `FOR UPDATE`; обидва шляхи перевіряють відсутність Run у сусідньому сховищі. Unique constraint `uq_workflow_plan_run` захищає canonical Plan. Під час створення відбувається **лише один** `WorkflowExecutionStore::create` і жодного `cos_federation_runs/steps` write.
2. `Advance` виконується під блокуванням **канонічного Run**. Конкурентні запити не створюють другий Step claim і не породжують другий Action.
3. Кожен Action має єдиний сталий ключ `wf:<sha256(tenant,run,step)>`. Після невизначеного результату система спершу шукає вже записаний Action за ключем і **не викликає повторного `submit`**.
4. Початковий Action одержує `PENDING_APPROVAL`. Step резервується в Workflow до реального human Approval. Worker повторно перевіряє approval, tenant, зв'язок із Plan, непорушний DAG, claim lease та попередні receipts.
5. Повторне виконання `COMPLETED`, `FAILED`, `REJECTED`, несумісних Action або Step із закінченим claim не допускається. Вони потребують ручної перевірки.
6. Підтвердження Step та Run виконується тільки після незалежної перевірки receipt першої успішної Action attempt. Бізнес-результат Goal оцінюється окремо через Domain-owned evidence providers.

## Межі реалізації

- Legacy-запуски продовжують існувати на старому шляху, доки не пройдуть backfill/fencing, і **не копіюються автоматично** у canonical. Це свідомий поетапний cutover.
- Шлях із `none`/не-Action steps не активовано. Він потребує власного safety contract для доказів.
- Реальні зовнішні наслідки не виконуються тестами архітектурної структури. MySQL перевірка admission є обов'язковою; потрібен наскрізний deployment acceptance.
- Step lease обмежено 24 годинами; прострочення вимагає розгляду оператором, а не повторного запуску. Автоматичного lease renewal немає.
- Канонічний Run, який потребує recovery невизначеного ефекту, не відновлюється через legacy Federation recovery API. Використовується штатне Workflow recovery з незалежними дозволами.

## Критерій наступного завершення

Міграція всіх Federation Goal Runs допускається тільки після перевірки активних legacy references, адитивного backfill з єдиним ID mapping, повної receipt-based parity за tenant, закриття невизначених Action, керованого rollback і перевірки Golden 50. Усі статуси виконання перевіряються по канонічних Run/Step, не по текстовій декларації Federation.

**Не вважати цей обмежений opt-in завершеною міграцією всієї COS.**

## Історичне перенесення та завершення роботи legacy

Нові Federation Runs тепер маршрутизуються **тільки** до Platform Workflow. Якщо tenant не має opt-in, створення блокується, без fallback на legacy. Історичні записи залишаються доступними для читання та окремого recovery, але legacy executor забороняє виклики `start` і `advance`.

Команда `cos:federation:history:cutover <organization> <run>` проводить read-only оцінку. Для **незапущених**, незмінених Run із нульовими Step attempts і без будь-яких `fed:*/wf:*` Actions оператор може встановити `COS_FEDERATION_HISTORICAL_MIGRATION=explicit_operator_approved` та виконати `--apply --operator=<id>`. Canonical Store створює Run/Step в тій самій DB-транзакції, Parity Reader підтверджує MATCH, після чого пишеться фіксуючий migration ledger. Жоден Action не запускається повторно.

Виконані, claimed, failed або неоднозначні legacy Actions **не імпортуються як нові Executions**. Вони є архівними доказами, які потребують human recovery/receipt reconciliation. Неперевірений ефект не можна перетворити в `wf:*` Action і вважати новою успішною спробою. Для такого Run команда поверне blocker замість удаваного PASS.

Non-Action кроки використовують `read:<kind>` і pinned `evidence` із `run_id`, `input_digest`, `reference` та доменно-специфічними полями. `Plan.run_id` потрібно визначити до незалежного людського погодження, а `input_digest` обчислюється як SHA256(organization + NUL + run + NUL + plan). Claim резервується один раз, а завершення можливе виключно за дійсним доменним receipt. Жодне read-evidence не замінює Approval чи фактичний Outcome.

Для старих Runs команда `cos:federation:history:cutover` повертає також `step_reconciliation`: стан кожного кроку, число історичних Action attempts, посилання на Action і причину, чому неможливе автоматичне перенесення. Позначка `independent_action_receipt_attestation_required` **не є перевіреним receipt** і не означає дозвіл повторити Action.


## P0: Безпека історичної міграції Federation

Historical migration is **no replay**. Only pristine, not-yet-dispatched
legacy Runs are promotable. All claimed, ambiguous, completed or missing
receipts remain archived for operator reconciliation, never converted into a
new `wf:` Action or silently marked successful.

### Окремі гарантії cutover і подальшої цілісності

- **Cutover parity** is checked once inside the promotion transaction, at the
  instant before the new authority is committed. The result `match`, the
  SHA-256 of the original legacy Run/Steps and the SHA-256 of the canonical
  immutable identity are recorded in
  `cos_federation_execution_migrations`. A matching append-only
  `cos_federation_migration_audit` row identifies the approving operator and
  the permit digest. If any part fails, the whole transaction rolls back.
- **Post-cutover integrity** is a separate read-only audit. It rechecks the
  frozen legacy source checksum, verifies the canonical definition / static
  binding checksum, independently checks pinned Plan Approval, and re-attests
  every completed canonical Action or Domain receipt. Runtime transitions
  (`created → running → completed`) are **excluded from the immutable
  fingerprint**: normal progress must not be counted as shadow parity drift.
  Results are `healthy`, `reconciliation_required`, or `drift`.
  Legacy changes or canonical static identity changes mean `drift`.
  Missing/uncertain receipts require reconciliation, not optimistic completion.

The read-only `cos:federation:history:cutover <org> <run>` output includes
`cutover_parity`, `post_cutover_integrity` and a per-Step
`step_reconciliation` report. Successful historical `fed:` receipts are
re-attested with the existing independent Action/Policy/Approval/first-attempt
checker when available, as `verified_historical_receipt_archive_only`.
**That result is archival evidence, not permission to execute, checkpoint
or requeue any old Action.** The global legacy Action worker remains retired.

### Авторизація оператора

The old `--operator=arbitrary-string` switch is no longer sufficient.
The privileged CLI accepts:

```bash
COS_FEDERATION_HISTORICAL_MIGRATION=explicit_operator_approved \
php symfony/bin/console cos:federation:history:cutover \
  '<tenant-id>' '<legacy-run-id>' --apply \
  --operator='<active-tenant-admin-tn_users-id>' \
  --permit-file='/run/secrets/federation-cutover-permit'
```

The separate authorizing service issues an asymmetric signed permit with the
grammar `v2:<unix-expiry>:<lowercase_hex_RSA_SHA256_signature>`. A trusted
authority signs the newline-separated UTF-8 payload using its **private
RSA-2048-or-stronger key**:

```text
federation-cutover-v2
<organization-id>
<run-id>
<operator-tn-users-id>
<source-sha256-from-read-only-inspect>
<expiry-unix-seconds>
```

The COS CLI only receives the **public** PEM key, base64-encoded in
`COS_FEDERATION_MIGRATION_AUTHORITY_PUBLIC_KEY_BASE64`. It verifies the
signature via `openssl_verify` using RSA/SHA-256. The private signing key
never reaches the migration CLI, operator shell, COS host or Actions runner.
The signer must independently authorize the human request, and its
private-key storage/access control is outside the COS application boundary.

The server verifies that the operator is a live `tn_users` identity with an
ACTIVE `admin` or `owner` membership for the exact tenant. It verifies the
public-key signature and rejects expired permits and expiry windows longer
than 15 minutes. A permit for another Run, tenant, actor or modified source
cannot authorize a transfer. Keep the **private key** in a separately controlled approval/signing service.
The public key is not a signing secret. A CLI operator cannot mint permits
merely by reading application configuration.

The migration ledger and unique constraints prevent re-application. The
migration audit contains only the **SHA-256 digest of the signed permit**, never its
raw signed value or private key. This is an operator-only pathway, not a public
controller or automatic background task.

Legacy migrations recorded before this safety pack have no canonical
identity baseline and are therefore reported as **requiring manual
reconciliation**, not retroactively declared `healthy` without proof.

### Критерії приймання

`tests/integration/federation_historical_cutover_mysql.php` checks real
MySQL tenant authorization, forged/missing permits, exactly one accepted
audit event, normal canonical advancement after migration, legacy checksum
drift, canonical immutable identity drift and no Action replay.
`tests/architecture/federation_migration_safety.php` prevents accidental
removal of the independent gates. Both are required by the fast Federation
MySQL job and/or full Runtime.

Do not turn off the environment rollout gate or batch-promote uncertain
historical Actions to make migration coverage appear green.

The CLI requires a **regular, non-symlink permission-restricted permit file**
(mode `0600` or stricter), rather than a raw `--permit` command-line value.
Provision the permit out of band and remove it after the one-time migration.
This prevents the signed authorization from being stored in shell history or
exposed by operating-system process argument inspection.
