# Wave 12.24 — Performance & Observability

## Мета

Wave 12.24 закриває експлуатаційну видимість Web Experience без створення другого observability-стеку.

Канонічні примітиви лишаються:

- `Kernel\\Observability\\StructuredLoggerInterface`;
- `Kernel\\Operations\\Contract\\MetricsRecorderInterface`;
- `X-Correlation-ID`;
- `cos_operational_metrics`.

## HTTP metrics

`HttpExecutionContextSubscriber` записує:

- `cos.web.http.requests`;
- `cos.web.http.duration_ms`;
- `cos.web.http.errors`.

Labels навмисно low-cardinality:

- `method`;
- Symfony `route`;
- `status_class`.

Correlation ID не використовується як metric label. Він лишається в structured logs для traceability.

Кожна HTTP-відповідь отримує:

- `X-Correlation-ID`;
- `Server-Timing: app;dur=...`.

## Browser telemetry

Same-origin endpoint:

`POST /telemetry/web`

Дозволені event types:

- `error`;
- `unhandledrejection`;
- `navigation`;
- `resource`.

Payload bounded. Query string від path відкидається. Повідомлення та source обрізаються. Endpoint має окремий rate limit.

Telemetry не є заміною Audit/History. Це runtime diagnostics, а не бізнес-аудит.

## Performance budgets

Live browser gate перевіряє канонічні сторінки на desktop і mobile:

- `/`;
- `/auth/login`;
- `/property/catalog`.

Budgets:

- TTFB ≤ 1000 ms;
- navigation duration ≤ 3500 ms;
- same-origin transfer ≤ 4 MiB;
- JavaScript transfer ≤ 1500 KiB;
- CSS transfer ≤ 1000 KiB;
- resources ≤ 120;
- DOM nodes ≤ 3000.

Це regression budgets для локального canonical runtime у CI, а не маркетинговий Lighthouse score.

## Принципи

1. Не дублювати logger/metrics infrastructure.
2. Не класти correlation/user/path у high-cardinality metric labels.
3. Telemetry failure не повинна ламати бізнес-відповідь.
4. Browser runtime використовує `sendBeacon` або `fetch(..., keepalive: true)`.
5. Performance regression має ламати CI конкретним metric/limit повідомленням.
