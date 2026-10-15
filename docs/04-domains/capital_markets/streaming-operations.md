---
title: Діагностика потокових даних Capital Markets
description: Команда перегляду worker lease та стану довіри до котирувань без зміни даних.
status: draft
kind: engineering
updated: 2026-10-15
---

# Діагностика потокових даних

Оператор має команду лише для читання. Вона показує ACTIVE, EXPIRED і
RELEASED для кожного worker shard, а також кількість TRUSTED, DEGRADED, STALE,
UNTRUSTED та UNAVAILABLE Market State в розрізі джерела та типу ринкових даних.

```bash
php symfony/bin/console cos:capital-markets:market-data:stream:status ORG
php symfony/bin/console cos:capital-markets:market-data:stream:status ORG --source=SOURCE
```

Команда не повертає worker owner ID, секрети, сирі котирування або токени
фенсингу. Вона не вмикає потоки, не перезапускає працівників, не звільняє
lease та не змінює довіру до ринкових даних. Відбір за організацією
обов'язковий і проходить незалежний MySQL-тест із чужим орендарем.

Це діагностика через серверний термінал, **не графічний операційний екран**.
Для виробничого запуску ще потрібні метрики затримки, алерти, інциденти
та контрольовані команди restart/stop з авторизацією й аудитом.
