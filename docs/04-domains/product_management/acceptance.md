---
title: Приймання Product Management P6–P7
description: Незалежне приймання нативного товарного бізнесу, live AI та зовнішніх провайдерів.
status: active
updated: 2026-11-26
kind: operations
---

# Приймання Product Management P6–P7

Цей протокол **не** вмикає модулі, не схвалює операції, не змінює
readiness і не запускає deployment. Product є автономним від Shopify.

## P6 — приймання реального AI/LLM

### Передумови

- Окремий staging tenant (не `default`), активні Product Management і Federation,
  актуальні міграції, справжні незалежні manager accounts та явні
  APPROVAL_REQUIRED policy для Plan і `product_management.governed.product.assemble`.
- Канонічний Workflow rollout, HMAC configuration
  `COS_AUTOMATION_TENANT_ORGANIZATION` (цей самий tenant) та
  `COS_AI_CAPABILITY_INTENT_SECRET`, та `COS_FEDERATION_CANONICAL_ORGANIZATION` (той самий tenant); налаштований реальний Kernel LLM
  route і бюджет. Canary використовує один bounded structured request
  з 900 output tokens і budget reservation limit 0.10 USD; це не гарантія
  тарифу провайдера. Не передавати секрети у командному рядку або артефактах.
- Зафіксований deployed source SHA, дозволений оператор і незалежний reviewer.
  Увімкнути лише для погодженого вікна:
  `COS_PRODUCT_AI_ACCEPTANCE_ENABLED=1`,
  `COS_PRODUCT_AI_ACCEPTANCE_ENVIRONMENT=staging`,
  `COS_PRODUCT_AI_ACCEPTANCE_ORGANIZATION=<isolated-tenant>`.
  Без усіх трьох параметрів команда заблокована; ніколи не вмикати у production.

### Захищений GitHub workflow

`COS Product P6 supervised live AI acceptance` має тільки ручний
`workflow_dispatch` з `main` і protected environment `AWS TN2026`.
Задати protected variable `COS_PRODUCT_AI_ACCEPTANCE_ORGANIZATION` лише
для дозволеного staging tenant. Inputs: exact deployed SHA, tenant,
`propose` або `inspect`; для inspect — exact intent/run. Workflow порівнює
GitHub source SHA, deployment manifest, host code/routes і active HTTP та
canonical worker code/routes, а тоді викликає лише наведений CLI.
Sanitized artifact `product-p6-live-evidence/evidence.json` містить
revision, timestamp, result та evidence URL; відсутність налаштувань
завершується помилкою, не створенням tenant/політики і не deployment.

### Сценарій

1. `php symfony/bin/console cos:product:ai-acceptance propose --organization=<isolated-tenant>`
   використовує існуючий governed LLM client. Модель генерує business
   proposal для чернетки з case-specific двома SKU та USD base prices
   12.50 / 18.75. Точний bounded JSON перевіряється перед existing
   signed intake. Немає підстановки fixture або примусового виправлення
   відповіді. Успіх тільки `PENDING_INDEPENDENT_REVIEW`, **не** live PASS.
2. Менеджер з дозволом переглядає незмінний digest у
   `/workspace/capabilities/intents` та adopts. У `/workspace/goals`:
   request Plan approval, другий менеджер схвалює, запускається canonical
   Workflow, окрема Action approval, execution worker, trusted native
   Goal evaluation. Зберегти intent/Goal/Plan/Run/Action/Evaluation IDs.
   Не використовувати self-approval чи прямі SQL зміни.
3. `php symfony/bin/console cos:product:ai-acceptance inspect --organization=<isolated-tenant> --intent-id=<intent> --run-id=<run>`.
   Read-only verification підтверджує реальний `cos_llm_usage` з provider
   request ID, signed payload digest, exact approved Plan і Run graph,
   canonical Action/Policy/Approval/attempt attestation та свіжий
   Product/Variant/Price/child receipt readback і stock reconciliation.
   `NATIVE_AI_READBACK_PASS` потрібен, але не є незалежним D3/D4/D5 sign-off.
4. Зберегти sanitized JSON output у контрольованому artifact і вимкнути
   acceptance flags. У разі помилки не підміняти результат; investigate
   existing intent/Run, повторний запит має окремий nonce.

CI перевіряє цей сценарій із синтетичним model envelope через signed
API controller, actual MySQL, реальні Plan/Action approvals та worker,
exact native prices і tampered readback fences. Fixture/missing
LLM telemetry **не** можуть пройти live evidence check.

## P7 — приймання релізу домену

CI job `Product Management P7 engineering evidence` залежить від
Runtime / Federation MySQL / Capital Markets Pack та видає artifact
`product-management-engineering-acceptance/engineering.json`.
Він окремо записує candidate SHA і **tested** SHA (PR merge ref
може відрізнятися від branch head). Тільки exact deployed release SHA
задовольняє фінальний gate. Це engineering evidence, не staged D3,
D4 або D5.

Згідно з [DAS-001 runbook](../../10-operations/das-001-domain-acceptance.md):

- D3: test tenant, full governed catalog, CSV, pricing, media, inventory,
  analytics, AI Goal/Plan/Action/Outcome; deny cross-tenant, missing approval,
  replay, drift; populated native evidence.
- D4: human manager Workspace journey, UX, exact business readback,
  незалежний user sign-off.
- D5: production canary на іншому погодженому tenant, readback, alerting,
  verified rollback або module disable.
- P6: artifact фактичного `NATIVE_AI_READBACK_PASS` на D3/D4 tenant.
- Shopify лише при свідомому підключенні каналу: окреме S1–S5 live
  acceptance, правильний магазин/валюта/ownership, дозволені операції.
  SERIES01 не продається.

Сформувати evidence JSON з полями:

- `engineering`: незмінний CI artifact вище;
- `pipelines`: 4 записи `CI`, `Documentation`, `Frontend`, `Runtime` з
  `name,tested_revision_sha,conclusion: success,evidence_url,performed_at`;
- `deployment`: `revision_sha,execution_source_sha256,routes_sha256,performed_at,evidence_url`
  з актуального deployment attestation;
- `independent_acceptance`: по одному D3/D4/D5
  `stage,revision_sha,environment,tenant,scenario,performed_at,result: PASS,business_readback,evidence_url,operator,independent_reviewer`.
  D5 також `alerting_verified:true,rollback_or_disable_verified:true`;
- `live_ai`: результат `inspect` + `revision_sha,environment: staging,performed_at,evidence_url`;
- за потреби Shopify: `shopify` з `revision_sha,tenant,result: PASS,stages:[S1,S2,S3,S4,S5],performed_at,evidence_url,operator,independent_reviewer,business_readback`.

Усі timestamps ISO timezone, evidence URLs HTTPS, business_readback
непорожні списки ідентифікаторів/immutable evidence. Не включати secrets або PII.

```bash
python3 bin/ci/product-release-acceptance.py verify \
  --revision <40-hex-deployed-sha> --evidence product-release.json
# add --include-shopify only when enabling this optional adapter
```

Неповні, іншої ревізії, fixture або self-reviewed evidence → nonzero BLOCKED.
`EVIDENCE_COMPLETE_FOR_INDEPENDENT_REVIEW` означає лише повноту
переданого пакета, **не** перевірену достовірність зовнішніх URL або
release authorization. Незалежний reviewer має звірити оригінальні
artifact, actor, deployed SHA та readback, і лише після цього вручну
оновити readiness у паспорті за DAS-001. Сам CI ніколи не підвищує readiness.
