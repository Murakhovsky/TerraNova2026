---
title: AI, кеш, пошук і файлове сховище
description: Канонічні межі provider-neutral AI, конфігурації, кешу, пошуку та файлового сховища COS.
status: active
updated: 2026-09-18
kind: architecture
contract: architecture-v1
---

# AI, кеш, пошук і файлове сховище

## 26. Провайдери AI

`Kernel/Agent` залишається provider-neutral. Канонічний Agent Runtime залежить від `LlmProviderInterface`, а конкретні HTTP/SDK інтеграції живуть тільки в Infrastructure.

`Infrastructure\AI\StructuredLlmAgentProvider` є мостом:

```text
AgentRuntimeEngine
      ↓
LlmProviderInterface
      ↓
StructuredLlmAgentProvider
      ↓
StructuredLlmClientInterface
      ↓
OpenAI / Anthropic / local / gateway adapter
```

Організація і correlation id передаються до structured LLM request явно. Схема відповіді агента формується в Kernel через `AgentOutputSchemaFactory`, тому HTTP transport більше не є власником бізнес-контракту відповіді агента.

Наявний `Platform\Knowledge\Contract\EmbeddingProviderInterface` лишається канонічною embedding-межею. Окремий tool-calling provider contract зараз не вводиться: виконання інструментів є відповідальністю `Kernel/Tool`, а не SDK конкретного LLM.

## 27. Ідентичність і безпека

Symfony Security, `IdentityResolverInterface`, `TenantContext`, permissions і tenant voter вже є канонічним runtime-шляхом. Дублювати `User`, `Employee`, `Contact` або `Client` однією універсальною сутністю заборонено: це різні моделі з різною відповідальністю.

Legacy identity допускається тільки за adapter boundary до завершення перенесення даних.

## 28. Конфігурація

Process environment є Infrastructure concern. `app/Kernel`, `app/Platform` і `app/Domains` не читають `getenv()`, `$_ENV` або `$_SERVER` напряму.

Поділ:

```text
DATABASE_URL / API keys / hostnames
        → Symfony / Infrastructure environment

rules / policies / agent tenant configuration
        → Kernel Configuration + repositories

майбутні tenant settings / feature flags
        → Platform contract + persisted tenant configuration
```

Feature flags не створюються як env-перемикачі бізнес-логіки лише заради зручності deployment.

## 29. Кеш

`Platform\Cache\Contract\CacheStoreInterface` задає framework-independent межу. Symfony adapter використовує runtime cache service. Кеш не є primary storage і не замінює repository.

## 30. Пошук

`Platform/Search` містить tenant-explicit `SearchQuery`, typed hits і `SearchEngineInterface`.

Для MVP дозволений MySQL-backed search. Meilisearch/OpenSearch додається тільки коли є виміряна потреба в окремому індексі.

## 31. Файлове сховище

`FileStorageInterface` описує binary storage, а `StoredFile` — технічні metadata файла. Це не `Document`: документ має бізнес-семантику, версії, permissions і relations; storage лише зберігає bytes.

Поточний local adapter безпечний для dev/MVP та забороняє path traversal. S3-сумісний adapter може бути доданий пізніше без зміни Platform contract.
