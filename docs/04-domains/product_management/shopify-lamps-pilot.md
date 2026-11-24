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

## Credential boundary

Never add Client Secret, an access token or an API response body to this
repository, CI logs, PR or issue. This repository intentionally contains
no client credentials. The sample store is non-production, but access
tokens are still valid credentials while installed.

For the *existing supervised single-store pilot*, the Symfony PHP container
accepts these host Docker Compose environment variables:

- `SHOPIFY_SHOP_DOMAIN=lamps-6eey3z3e.myshopify.com`
- `SHOPIFY_CLIENT_ID` from the app's Dev Dashboard
- `SHOPIFY_CLIENT_SECRET` from the app's Dev Dashboard
- `COS_SHOPIFY_OPERATOR_ENABLED=1` only while a supervised operator runs

The Docker Compose host `.env` is excluded by `.gitignore`.
The PHP service alone inherits these settings, not the generic worker fleet.
Compose environment variables are visible to Docker administrators; a
production rollout should use the existing COS Credential Vault instead.

The isolated no-output GitHub Actions smoke workflow
`.github/workflows/cos-shopify-lamps-smoke.yml` reads a separate
root-owned file on the COS VPS, `/etc/cos/shopify-lamps.env`, mode 0600.
The file contains only the three `SHOPIFY_*` variables above; it does
not authorize the import or trigger domain mutations. The workflow is
manual-only and checks the pinned domain before requesting a token.

## Verification

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
