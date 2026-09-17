# COS Platform Integration

`Platform\\Integration` is the canonical anti-corruption layer between COS domains and external systems.

## Dependency direction

```text
Domain/Application
    ↓
Platform Integration contracts
    ↓
Infrastructure adapter
    ↓
External SDK / API
```

A domain service must never call Telegram, Gmail, Google, OpenAI, a marketplace, CRM, ERP or telephony SDK directly.

## Canonical concepts

- `ConnectorDefinition` — provider-independent capability description.
- `Connector` — organization-scoped enabled connector configuration.
- `Connection` — concrete external account/workspace connection.
- `Credential` — secret reference and scopes. Raw secrets do not live in the model.
- `Webhook` — normalized inbound external event.
- `ExternalResource` — mapping/snapshot of an external object.
- `SyncJob` — observable synchronization execution.

## Contracts

- `ConnectorInterface`
- `WebhookHandlerInterface`
- `ExternalApiClientInterface`
- `ConnectorRegistryInterface`
- `CredentialVaultInterface`

Vendor SDKs belong to `Infrastructure/Integration/<Provider>` adapters. Existing legacy integrations migrate behind these contracts incrementally.
