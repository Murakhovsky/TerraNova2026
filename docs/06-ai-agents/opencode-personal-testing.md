---
title: OpenCode V2 — особистий тестовий адаптер COS
description: Безпечне підключення ручних запитів адміністратора COS до OpenCode V2 з підпискою ChatGPT.
status: draft
updated: 2026-10-22
kind: architecture
---

# OpenCode V2 у COS

## Межа відповідальності

`OpenCodeStructuredLlmClient` реалізує canonical `StructuredLlmClientInterface` і зареєстрований як provider `opencode`. Підключення **не замінює** OpenAI API, LLM за замовчуванням, COS Goals, Workflow, Engineering або автоматичні агентні виклики. `complete()` допускає тільки `useCase=opencode.test`, непорожні actorId та organizationId. Звичайні клієнтські/фонові use cases блокуються.

Зовнішній запит: `POST /api/experimental/generate` (OpenCode V2). Формат: `{"prompt":"...","model":{"providerID":"openai","id":"..."}}`; модель необов'язкова, тоді використовується default OpenCode. Відповідь: `{"data":{"text":"{\"answer\":\"...\"}"}}`. Транспорт повертає structured JSON, без додаткових інструментів.

## Умови інсталяції

1. Перевірити на Linux сервері під тим самим **окремим непривілейованим** користувачем `opencode auth list` та ручний `opencode run`.
2. Запустити `opencode serve --hostname 127.0.0.1 --port 4096` із `OPENCODE_SERVER_PASSWORD`, не публікувати порт.
3. У середовищі **PHP контейнера** переконатися, що OpenCode reachable. Контейнерне `127.0.0.1` не дорівнює loopback AWS-хоста. Потрібен sidecar у приватній мережі Docker без published ports або окремий обмежений внутрішній тунель.
4. Для приватного sidecar застосовувати `COS_OPENCODE_ENDPOINT=http://opencode:4096` та `COS_OPENCODE_ALLOWED_HOST=opencode`; endpoint має збігатися з дозволеним hostname, HTTP only, Basic Auth.
5. У PHP runtime задати:
   - `COS_OPENCODE_ENABLED=1`
   - `COS_OPENCODE_ENDPOINT=http://127.0.0.1:4096` або приватний sidecar host
   - `COS_OPENCODE_ALLOWED_HOST=127.0.0.1` або приватний sidecar host
   - `COS_OPENCODE_SERVER_USERNAME=opencode`
   - `COS_OPENCODE_SERVER_PASSWORD=<не комітити; той самий секрет, що на сервері OpenCode>`
   - `COS_OPENCODE_ALLOWED_ORGANIZATION_ID=<ID твого tenant у COS>`
   - `COS_OPENCODE_ALLOWED_ACTOR_ID=<ID твого користувача в COS>`
6. Відкрити `/admin/settings/llm` → «OpenCode: тест через ChatGPT-підписку» → «Тестувати OpenCode».

Авторизацію OpenCode в COS не копіюємо. Її зберігає OpenCode під локальним обліковим записом. Не монтувати директорію з credential-файлом в основний COS контейнер.

## Обмеження й acceptance

- Адміністратор + CSRF, manual POST, 15-секундний інтервал, max 500 символів.
- Feature flag default OFF, пароль required, endpoint hostname exact allowlist, HTTP only, no redirects.
- Stateless Generate route, жодних OpenCode tool/session/shell/file APIs, без автоматичних retries.
- Тестові результати виводяться лише у HTML відповіді адміністратора, з `no-store`, без Query String.
- Факт використання ChatGPT Plus та ліміти регулюються OpenCode/OpenAI. Не дозволено використовувати особисту підписку як загальний комерційний LLM API.
- Відсутність обліку token usage/cost у OpenCode V2 Generate означає, що це **ручний тестовий режим**, а не production-governed provider. Для production потрібен окремий provider + usage ledger + policy approvals.

Приймання по живому середовищу: `opencode auth list` PASS, `GET /api/info` зі Basic Auth PASS, POST Generate повертає `data.text`, COS admin тест повертає `answer`, звичайний user 403, cron/agent `opencode.test` не призначений до routing та не викликається.
