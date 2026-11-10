---
title: Capability Fleet Consistency — однаковий release SHA і контракти на всіх репліках
description: Операторський read-only gate для контрольованого приймання runtime без production mutations.
status: implementation-draft
updated: 2026-10-30
kind: operations
---

# Приймання Capability Library на всіх runtime-інстансах

## Мета

Перед production cutover перевіряємо, що кожен очікуваний вебконтейнер та Action worker використовують один набір typed contracts, однакові fingerprints, заявляють один SHA та мають вимкнений Workflow execution gate. Це **узгодженість зібраних звітів**, а не криптографічна атестація deployment, перевірка tenant permissions або дозвіл запустити Action.

Жоден інструмент цього інкременту не має SSH, HTTP-клієнта, читання production MySQL, Domain mutation або механізму зміни Worker DI.

## Збір свідчення

На кожній цільовій репліці оператор незалежно встановлює фактичний release commit і ID інстанса із контрольованого середовища та виконує:

~~~bash
php bin/console cos:capability:runtime:witness \
  --instance-id=web-01 \
  --instance-role=web \
  --deployment-sha=<40-символьний-release-SHA> \
  --require-action-bindings > web-01.json
~~~

Повторити для кожного `worker` з `--instance-role=worker`. Команда працює в корені Symfony, не в корені монорепозиторію. Прапор `--require-action-bindings` відхиляє missing Action handlers. **Наявність fail-closed gate очікувана і правильна**. Поля instance ID, role і SHA поки залишаються **операторськими твердженнями**, а не перевіреним криптографічним provenance.

## Маніфест контрольованого складу

Приклад із двома репліками:

~~~json
{
  "format": "cos-capability-fleet-manifest-v1",
  "release_sha": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
  "expected_instances": [
    {"id": "web-01", "role": "web"},
    {"id": "worker-01", "role": "worker"}
  ]
}
~~~

У реальному запуску SHA має бути верифікований за GitHub release/deployment та реальним образом, а не скопійований наосліп із запиту. Це свідчення не містить секретів або токенів.

## Зіставлення звітів

На захищеній операторській машині, де є PHP і Composer autoload з того самого checkout:

~~~bash
php bin/cos-capability-fleet-consistency.php \
  --manifest=/secure/fleet.json \
  --witness-dir=/secure/witnesses
~~~

Очікуються всі інстанси без дублювань; **кожен** звіт має правильний SHA, роль, digest, реєстрацію typed Action handlers та `WORKER_DISABLED_FAIL_CLOSED`. Відсутній worker, невірний SHA, різні contracts, пустий/фальшивий handler або самопроголошена сертифікація дають `FAIL_CLOSED` і ненульовий exit code.

Успішний результат повертає `CONSISTENT_UNCERTIFIED`, `production_certified=false`, `deployment_provenance_verified=false`, `operator_sign_off_required=true`.

## Що залишається для фактичного production acceptance

1. Справжні release SHA/digest інстансів мають підтверджуватися незалежним deploy inventory та контрольованою provenance/підписом, а не лише переданим CLI параметром.
2. Для кожного tenant потрібно виконати окремий Role/Permission/Module smoke, не лише inventory реєстрації.
3. Єдиний Plan→Workflow→Action має пройти незалежне Human Approval, latest Policy, єдину спробу, crash/retry, unknown/manual reconciliation.
4. Growth/Sales/Documents та інші Domain owners мають засвідчити native readback і Goal Outcome окремо. Технічний receipt не дорівнює бізнес-результату.
5. Рішення змінити production `FailClosedWorkflowActionWorkerAdmission` вимагає окремого операційного дозволу та rollback plan. **Цей PR worker не вмикає**.

## Ризик злиття в main

`.github/workflows/runtime.yml` зараз має `on.push.branches: [main]` і jobs публікації GHCR та deploy в AWS dev при кожному відповідному push. Навіть merge тільки бібліотеки в `main` запустить ці side effects. Тому перед серією merge #415→#421 оператор має окремо підтвердити безпечний режим deploy або змінити release workflow під рев'ю. Не обходити це шляхом прихованого `[skip ci]`.

## Тести

`tests/unit/cos_capability_fleet_consistency.php` перевіряє успішну несертифіковану узгодженість та негативні сценарії: missing/duplicate worker, SHA mismatch, contract drift, handler absent, фальшивий provenance, несумісну role, підміну ID чи count. CI Runtime запускає witness у Docker Symfony із конкретним SHA pull request і зберігає непідписаний JSON для огляду.
