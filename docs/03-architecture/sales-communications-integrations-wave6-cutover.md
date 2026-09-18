---
title: Перенесення Communications та Integrations у Symfony
description: "Шоста хвиля другої фази міграції COS: канонічний Symfony boundary для integration control plane, CRM webhook ingress, durable inbox, Messenger processing та Sales communications."
status: active
updated: 2026-09-18
kind: architecture
contract: architecture-v1
---

# Перенесення Communications та Integrations у Symfony

Wave 6 робить Symfony канонічним HTTP/application boundary для керування Sales integrations, приймання зовнішніх CRM events та відправлення communications. Наявні Integration contracts, provider adapters і Sales operation services залишаються авторитетними.

## Межі Wave 6

Wave 6 переносить у Symfony:

- integration catalog та tenant-scoped integration administration;
- routing configuration і configuration revisions;
- CRM webhook ingress;
- durable CRM inbox;
- асинхронну обробку inbox через Symfony Messenger;
- recovery sweep для недоставлених у Messenger inbox items;
- outbound Sales communications через наявний provider gateway.

Wave 6 не створює нового provider SDK і не дублює `Platform\Integration`.

## Канонічний control plane

```text
UI / API
  ↓
Symfony V1
  ↓
CommandBus / QueryBus
  ↓
Integration Application
  ↓
SalesIntegrationAdministrationInterface
  ↓
MySQL configuration adapter
```

Write-сценарії вимагають tenant `MANAGE`, активний Sales module, CSRF, correlation id та `X-Idempotency-Key`.

Configuration writes виконуються транзакційно. Semantic idempotency використовує canonical payload hash: той самий key з тим самим payload є replay, а той самий key з іншим payload повертає conflict.

## CRM webhook ingress

Канонічний шлях inbound event:

```text
External CRM
  ↓ HTTPS + HMAC
/api/v1/integrations/crm/{integrationId}/webhook
  ↓
active integration lookup
  ↓
integration-scoped credential
  ↓
durable cos_crm_inbox
  ↓ commit
Symfony Messenger
  ↓
ProcessCrmInbox
  ↓
CRM anti-corruption mapping
  ↓
Sales operation / domain event
```

Webhook secret визначається за конкретними `integration_id + organization_id + provider`. Це не дозволяє двом connections одного provider випадково використовувати один чужий credential context.

Підпис обчислюється як HMAC-SHA256 від raw HTTP body. Credential references зберігаються як references на зовнішні secrets і не повертаються через API.

## Idempotency inbound events

Унікальна зовнішня identity:

```text
organization + provider + external_event_id
```

Повтор з тим самим event type і payload повертає вже існуючий inbox item. Повтор того самого `external_event_id` з іншим payload або event type відхиляється як semantic idempotency conflict.

Це важливо, бо `INSERT IGNORE` без перевірки payload перетворює upstream помилку на тиху втрату даних.

## Durable inbox та recovery

MySQL inbox є durable source перед async processing. Messenger dispatch виконується після commit.

Між MySQL commit і Redis dispatch немає distributed transaction, тому Wave 6 додає recovery path:

```text
cos_crm_inbox RECEIVED / FAILED
  ↓
SweepCrmInboxCommand every 1 minute
  ↓
Messenger
  ↓
ProcessCrmInboxCommand
```

Таким чином тимчасова недоступність Redis після приймання webhook не залишає event назавжди без обробки.

Messenger використовує наявну retry policy та failed transport.

## Communications

Outbound communication проходить через канонічний Sales operation:

```text
POST /api/v1/sales/opportunities/{id}/communications
  ↓
SendSalesCommunicationCommand
  ↓ Messenger
SalesOperationService
  ↓
MessageGatewayInterface
  ↓
RoutedCrmGateway
  ↓
provider adapter
  ↓
sales_communications + sales.message.sent
```

API синхронно перевіряє tenant, Sales module, CSRF, supported channel, body size та idempotency key, після чого повертає `202 Accepted`.

Application handler додатково перевіряє semantic idempotency за `opportunity + channel + body`. Provider adapter зберігає власний stable idempotency reference перед зовнішнім side effect.

## Tenant та security boundary

Integration administration:

- потребує tenant `MANAGE`;
- завжди scope-иться organization id з authenticated tenant context;
- не приймає organization id з request payload;
- не повертає credential reference;
- конфігураційні зміни пишуть audit з correlation id.

Webhook ingress:

- не використовує browser session або CSRF;
- приймає тільки active CRM integration;
- перевіряє HMAC до persistence;
- не довіряє organization/provider з payload;
- обмежує raw payload до 1 MiB.

Communications:

- потребують tenant `ACCESS`;
- opportunity завжди перевіряється persistence layer у межах organization;
- mutation проходить через existing SalesOperationService, а не прямий SQL з controller.

## Definition of Done Wave 6

Wave 6 вважається завершеним, коли одночасно виконуються:

1. integration control plane доступний через Symfony V1;
2. controllers не залежать напряму від PDO або `Domains\...`;
3. webhook HMAC прив'язаний до конкретної integration;
4. inbox має duplicate-payload conflict detection;
5. async processing проходить через Messenger;
6. існує scheduler recovery sweep;
7. outbound communications використовують SalesOperationService;
8. admin і communication writes мають semantic idempotency;
9. mutations мають audit та correlation id;
10. tenant/module/CSRF boundaries перевірені;
11. architecture, unit та end-to-end runtime tests проходять у CI.
