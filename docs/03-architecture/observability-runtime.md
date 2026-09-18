---
title: Спостережуваність Symfony runtime
description: Correlation context та structured HTTP logging у канонічному Symfony runtime.
status: active
updated: 2026-09-18
kind: architecture
contract: architecture-v1
---

# Спостережуваність Symfony runtime

## 37. Спостережуваність

COS уже мав structured logger і telemetry для Queue, Action та external calls. Symfony migration додає спільний HTTP correlation contract, а не другий logging framework.

Кожен HTTP request отримує `X-Correlation-ID`:

- валідний caller-provided id зберігається;
- відсутній або небезпечний id замінюється;
- той самий id повертається у response header;
- completion log містить correlation id, source, tenant/actor коли вони відомі, method, path, status і duration.

Framework-independent primitives:

```text
Kernel\Observability\CorrelationId
Kernel\Observability\ExecutionContext
Kernel\Observability\StructuredLoggerInterface
```

Symfony-specific subscriber живе в Infrastructure.

## Межі відповідальності

Correlation context не замінює Audit. Observability описує виконання request і runtime-поведінку; Audit фіксує значущі дії, рішення та зміни стану. Correlation id може зв'язувати ці записи, але не перетворює технічний log на бізнес-аудит.
