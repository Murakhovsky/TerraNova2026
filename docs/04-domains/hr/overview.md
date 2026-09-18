---
title: Огляд домену HR
description: Межа V1 домену HR для працівників, посад, кандидатів, рекрутингу, онбордингу та performance lifecycle.
status: active
updated: 2026-09-18
kind: domain
contract: domain-v1
---

# Огляд домену HR

HR `0.1.0` є канонічним V1 skeleton-domain COS. Він фіксує предметну мову, але ще не вмикає runtime, persistence або HTTP surface.

## Призначення

```text
Candidate → Recruitment → Employee → Onboarding → Performance
                     ↘ Position
```

Домен володіє поняттями `Employee`, `Position`, `Candidate`, `Recruitment`, `Onboarding` і `Performance`.

## Поточний стан

```text
id: hr
version: 0.1.0
runtime: disabled
persistence: none
routes: none
process model: explicitly deferred
```

Відсутність executable process у V1 є явним architecture exemption до появи реальних HR use cases.

## Межі

HR не є Identity: `Employee` описує трудовий контекст, тоді як цифрова автентифікація, account і доступ залишаються в Identity. Файли та документи HR використовують Platform Documents, а не власне файлове сховище.
