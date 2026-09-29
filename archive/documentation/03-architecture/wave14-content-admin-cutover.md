---
title: Перенесення Manager Content/Admin на Symfony
description: "Wave 14 міграції COS: адміністративний контентний workspace, редагування та збереження матеріалів переходять з Phalcon на Symfony зі збереженням manager, CSRF і n8n contract."
status: active
updated: 2026-09-20
kind: architecture
contract: architecture-v1
---

# Перенесення Manager Content/Admin на Symfony

Wave 14 переносить адміністративну поверхню Content з Phalcon на Symfony без переписування самого Content Domain.

## Канонічна Symfony-поверхня

- `GET /admin/content`
- `GET /admin/content/edit`
- `GET /admin/content/edit/{id}`
- `POST /admin/content/save/{id}`

Контролер повторно використовує наявні `ContentServiceInterface`, `PhtmlRenderer`, workspace navigation та Symfony tenant context.

## Межа безпеки

Автентифікація читає існуючу legacy PHP session через read-only Symfony bridge. Manager authorization залишається явною. Мутації перевіряють наявний legacy CSRF token через `LegacySessionCsrfValidator`; Symfony не записує legacy session.

## Виведена legacy-поверхня

- `Interfaces\\Web\\Controller\\ContentController`
- усі декларації `/admin/content...` у `FrontendRoutes`

Host nginx передає Content administration surface безпосередньо Symfony runtime.

## Перевірка

CI перевіряє unauthenticated redirect, заборону для non-manager, manager list/edit SSR, відхилення неправильного CSRF, успішне збереження контенту, створення revision та постановку події в n8n outbox.
