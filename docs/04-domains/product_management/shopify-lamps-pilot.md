---
title: Shopify Lamps — тестовий read-only API пілот
description: Базовий запуск Shopify E4 після збереження реквізитів поза Git.
status: draft
updated: 2026-11-24
kind: domain
contract: domain-v1
---

# Shopify Lamps — E4 read-only пілот

Shop: `lamps-6eey3z3e.myshopify.com`. Existing Dev Dashboard custom app: COS API.
The integration already uses Shopify client credentials grant to obtain a
24-hour Admin API access token; a separate manually copied Admin API token
is **not** required for this flow.

## Межа доступу до реквізитів

Never add Client Secret, an access token or an API response body to this
repository, CI logs, PR or issue. This repository intentionally contains
no client credentials. The sample store is non-production, but access
tokens are still valid credentials while installed.

For the supervised single-store pilot, leave the three credentials in the
root-owned host file `/etc/cos/shopify-lamps.env` (0600), **not** in Docker
Compose, GitHub or a permanent web/worker process environment.
The guarded `bin/shopify-lamps-operator.sh` wrapper forwards credentials
to exactly one transient PHP CLI process over standard input (STDIN).
Future multi-store use should resolve a tenant-scoped COS Credential Vault.

The isolated no-output GitHub Actions smoke workflow
`.github/workflows/cos-shopify-lamps-smoke.yml` reads a separate
root-owned file on the COS VPS, `/etc/cos/shopify-lamps.env`, mode 0600.
The file contains only the three `SHOPIFY_*` variables above; it does
not authorize the import or trigger domain mutations. The workflow is
manual-only and checks the pinned domain before requesting a token.

## Перевірка

The independent smoke checks:
1. SSH to the configured COS VPS (existing environment secrets).
2. Root-owned secret file exists with exact 0600 permissions.
3. Expected shop, Client ID and Client Secret are present.
4. OAuth client credentials exchange succeeds, without printing tokens.
5. A GraphQL `shop` + `products(first:1)` query succeeds.
6. Output is status flags only, never credentials, source product records,
   token bodies or private customer data.

The COS operator's separate `cos:product:shopify provision` command
requires an **enabled Product Management tenant** and explicit
`PROVISION_READ_ONLY` confirmation. Provisioning, bounded pull,
immutable preview, independent approval and native import are different
steps. This smoke never starts a write.

Only scope for the pilot: `read_products`, `read_inventory` and
`read_locations` when required by the location query. Additional app
scopes do not prevent reads, but they expand potential impact if a
credential is compromised.


## Межі запуску

This pilot does not store credentials in GitHub, remotely configure the VPS,
merge the branch, enable Product Management tenants, or claim a successful
OAuth connection before its manual read-only smoke finishes. After a
successful read-only canary, use the existing independent Goal/Plan/Approval
Workflow for any native materialization. Shopify remains read-only.

## Операторський запуск Product Management

Переконайтесь, що код PR розгорнуто на сервері та модуль
`product_management` встановлено і увімкнено для конкретного COS TENANT.
IP allowlist Shopify має пропускати **фактичну вихідну** IPv4/IPv6 адресу.
Серверні секрети лишаються у `/etc/cos/shopify-lamps.env`; жодних токенів
не виводити у термінал чи журнали.

```sh
sudo bash bin/shopify-lamps-operator.sh TENANT_ID provision
sudo bash bin/shopify-lamps-operator.sh TENANT_ID pull CONNECTION_ID
sudo bash bin/shopify-lamps-operator.sh TENANT_ID candidates CONNECTION_ID
sudo bash bin/shopify-lamps-operator.sh TENANT_ID status CONNECTION_ID
```

`provision` повертає `connection_id`; використовуйте його в наступних
командах. Повторно provision для вже підключеного магазину не запускайте:
унікальна пара tenant/shop зупинить дублікат. `pull` читає до п'яти
Shopify-товарів на сторінку; це тільки зовнішні спостереження COS,
не створення Product/Price/Stock і не Shopify writeback.

`candidates` повертає до 20 метаданих Product GID, SHA-256 джерела,
стан повноти, свіжість і перевірку валюти. Поле
`stage_preconditions_met` не гарантує успішного stage: SKU, мапінг та
Plan-конфлікти перевіряються окремо. Для вибраного GID/hаsh:

```sh
sudo bash bin/shopify-lamps-operator.sh TENANT_ID stage CONNECTION_ID 'gid://shopify/Product/123' SOURCE_HASH
```

Stage створює лише незмінний Preview. Native Product створюється тільки
після окремого Goal → Plan → незалежного Approval → Platform Workflow.

Обгортка запускається адміністратором із `/opt/cos`, перевіряє
власника й права 0600 на файлі реквізитів. Ці реквізити не потрібно
додавати в `/opt/cos/.env` або перезапускати PHP-контейнер, якщо
застосунок уже розгорнуто з актуальним Product Management кодом.

## Автоматична read-only перевірка OAuth після merge

Зміна `.github/workflows/cos-shopify-lamps-smoke.yml` запускає
на `main` одноразовий live smoke без введення реквізитів у чат:
через існуючі SSH secrets GitHub Environment команда на COS VPS
зчитує root-owned `/etc/cos/shopify-lamps.env`, виконує OAuth та
`shop { name } products(first: 1)` через Shopify Admin GraphQL.
Це лише перевірка авторизації та читання Shopify, **не** підтвердження
tenant connection у COS, не `provision` і не `pull` у доменну базу.
Підсумок фіксується в GitHub Actions без токенів і каталогу.

## Керований запуск через GitHub Actions

Workflow: `.github/workflows/cos-shopify-lamps-operator.yml`, тільки
`workflow_dispatch` на `main`, середовище `AWS TN2026`.
Власник GitHub доступу обирає tenant organization та дію в Actions.
Секрети залишаються **лише на COS VPS**, в `/etc/cos/shopify-lamps.env`
(0600). У workflow передаються тільки ID, дія та SSH credentials із
існуючого захищеного GitHub Environment. Жоден Shopify secret/token не
потрапляє в Actions inputs або логи.

Порядок безпечної перевірки:

1. `preflight` — лише перевіряє installed/current/configured/enabled
   `product_management` для вказаної організації. Не активує модуль,
   не читає Shopify credentials та не звертається до Shopify.
2. `provision` — **явний ручний** OAuth preflight та одноразова
   реєстрація готового Shopify connection в COS. Не повторювати, якщо
   `connection_id` уже існує.
3. `pull` — тільки одна сторінка read-only Shopify каталогу.
4. `candidates` та `status` — тільки читання за `connection_id`.

`preflight` доводить лише стан tenant-модуля. Не підтверджує, що
деплой актуальний, міграції застосовані, IP allowlist пропускає VPS
або що Shopify OAuth/GraphQL успішні. Це підтверджує лише окремий
live `provision` і `pull`. Якщо відсутній чинний tenant або модуль не
активований, далі не рухаємось. Дії `stage`, `audit`, зміни COS
Product/Stock і Shopify remote writes навмисно не доступні через
цей workflow. Використовувати тільки тестовий tenant і магазин.
