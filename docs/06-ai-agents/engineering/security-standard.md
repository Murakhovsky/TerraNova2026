---
title: Engineering Security Standard
description: Обов’язкові перевірки безпеки та ізоляції для автономної розробки.
status: active
updated: 2026-10-04
kind: standard
---

# Стандарт безпеки

Every relevant feature must explicitly verify tenant isolation, authentication, authorization, ownership and least privilege. Untrusted input must be validated. Agents must consider SQL/command injection, XSS, CSRF, SSRF, unsafe serialization, file/path traversal, secret exposure and unintended data disclosure.

Repository mutations may never target .git, .env, vendor, node_modules, var, absolute paths or traversal paths. Production deploy and main merge remain human/CI authority.
