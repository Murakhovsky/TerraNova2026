---
title: Відокремлення Creative Draft та рекомендацій аналітика
description: Рішення про авторитет Marketing у людських чернетках, версійованих креативах і незалежній перевірці пропозицій.
status: draft
updated: 2026-11-19
kind: decision
---

# MKT-ADR-002: Пропозиція не є рекламним дозволом

Пакет M2a надає Marketing власні чернетки креативів і прогнозні рекомендації. При цьому **Product** залишається source of truth для товару, варіацій, запасів і цін, а **Finance** — для фінансових лімітів. Поточні Product PR #433 і Finance PR #436 не прийняті у `main`; жодне зовнішнє Product/Finance читання не вважається готовим.

1. Creative прив'язується до tenant-owned Campaign; усі версії append-only, містять hash та author. Стара ревізія не може бути «випадково» погоджена після редагування.
2. Поля `product_ref`, `destination_url`, `media_sha256` є лише посиланнями з позначкою `UNVERIFIED`; жодного перетворення на Product, Stock або Provider факт.
3. Пропозиція зберігає source reference, comment і статус `UNVERIFIED`; `origin=HUMAN` встановлює код, не браузер. Зараз це людська схема прийняття рішень, а не інстальований Hermes agent.
4. Автор пропозиції не може бути її reviewer. Перевірка включає immutable Campaign + Creative revisions; `ACCEPTED` означає лише схвалену пораду.
5. Усі записи тільки в Marketing, жодного Meta write, Finance mutation або нового автономного виконавця.
6. Після прийняття Product read API розробляється tenant-safe verified Product snapshot і передпублікаційний gate. Для реального Hermes потрібні scoped delegation, контракт evidence, origin-attestation і окрема людська approval.
7. D3–D5 залишаються відкритими до реального інтеграційного й користувацького acceptance.
