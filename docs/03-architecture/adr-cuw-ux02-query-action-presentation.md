---
title: ADR — UX-02 універсальний вигляд Query та Action
description: Представлення підтверджених Capability контрактів без другого виконавця.
status: isolated-pilot
---

# UX-02 Query / Action

Canonical Capability Library і CanonicalCapabilityGateway є єдиними власниками typed контрактів та допуску. UnifiedCapabilityPresentation містить вузький **allowlist UI-представлень**, а не Registry та не нову систему виконання. Невідомі декларації, зовнішні/ризикові Action та ті, що не мають перевіреного UI, показуються недоступними з причиною. Поле execution_verified не виводиться зі стану route або manifest.

Read-only Property Get/Search проходять через поточний typed Gateway з origin trusted_read. Людське створення через форму відправляється в наявний CSRF/idempotency Command endpoint; Bulk лише відкриває погоджений UI. Немає безпосереднього ORM, REST fetch або оптимістичного success. Server return відображає результат Query, а незавершена Action не видається за виконану.

Контракти для інших Domain і довільні JSON-схеми не перетворюються на генеровані writable форми до їхнього окремого приймання.
