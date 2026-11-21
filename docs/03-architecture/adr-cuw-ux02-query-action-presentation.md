---
title: ADR — UX-02 універсальний вигляд Query та Action
description: Представлення підтверджених Capability контрактів без другого виконавця.
status: isolated-pilot
updated: 2026-11-20
kind: architecture
---

# UX-02: запити та дії

Canonical Capability Library і CanonicalCapabilityGateway є єдиними власниками typed контрактів та допуску. UnifiedCapabilityPresentation містить вузький **allowlist UI-представлень**, а не Registry та не нову систему виконання. Невідомі декларації, зовнішні/ризикові Action та ті, що не мають перевіреного UI, показуються недоступними з причиною. Поле execution_verified не виводиться зі стану route або manifest.

Read-only Property Get/Search проходять через поточний typed Gateway з origin trusted_read. Людське створення через форму відправляється в наявний CSRF/idempotency Command endpoint; Bulk лише відкриває погоджений UI. Немає безпосереднього ORM, REST fetch або оптимістичного success. Server return відображає результат Query, а незавершена Action не видається за виконану.

Контракти для інших Domain і довільні JSON-схеми не перетворюються на генеровані writable форми до їхнього окремого приймання.

## Завершення людської команди

Форма на Unified Workspace використовує наявний Web-контролер CapabilityLibraryController та CanonicalCapabilityGateway. Параметр return_to приймає лише фіксоване внутрішнє значення unified, не довільні URL. Після успіху відбувається перехід на /workspace/unified/property?reference=… із повторним native Domain Query; непідтверджений результат — fail-closed. Browser тест перевіряє реальний write і same-key replay без нового виконавця.

## Обмеження експозиції

Статус typed у Capability Library сам по собі не створює картку бізнесової дії. UX-02 показує лише capability ID, для якого є явний human-readable presenter; інші навіть typed declarations лишаються в технічному каталозі до окремого UI acceptance. Це не дублюючий реєстр команд: право й активність операції завжди беруться з canonical Gateway.

## Локалізація UX-02

Використано ті самі Symfony UK/EN каталоги, що й у Shell. UI дій, форми та Property Query мають `uw.*` ключі, окремі доменні підписи capabilities мають точні `|trans` mappings. Пошукові дані з Read Models не перекладаються автоматично. E2E тест змінює мову через наявний локальний перемикач і перевіряє справжній Query view.
