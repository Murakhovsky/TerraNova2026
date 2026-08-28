# Frontend API conventions

Baseline date: 2026-08-27.

- New public endpoints use `/api/v1`. Existing `/api/property/*` URLs remain compatibility routes during the deprecation window.
- Successful JSON is `{ "ok": true, "data": ... }`. Failed JSON is `{ "ok": false, "error": "stable_code", "message": "human text", "errors": ... }`.
- Collection endpoints accept query filters and return `data.pagination` with `page`, `per_page`, `total`, and `total_pages`.
- Session-authenticated writes require the CSRF token in `X-CSRF-Token` or `csrf_token`. Webhooks use their integration signature and idempotency key instead of session CSRF.
- Critical externally retried commands require `X-Idempotency-Key`; duplicate keys return the original accepted outcome.
- The browser calls JSON through `resources/frontend/api/client.js`, which normalizes HTTP and envelope failures as `ApiError`.
- Canonical SSR pages remain indexable; admin/cabinet/API responses are `noindex` or JSON and are not a client-side SEO source.

The machine-readable baseline is `docs/api/openapi.yaml`.
