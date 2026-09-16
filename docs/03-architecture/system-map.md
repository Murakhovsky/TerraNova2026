---
title: Карта системи COS
description: Карта переходу від продукту й бізнес-процесів до доменів, середовища виконання, контрактів, інтерфейсів і точного технічного довідника COS.
status: active
updated: 2026-09-16
kind: architecture
contract: architecture-v1
---

# Карта системи COS

<div class="cos-branch-contract">
  <span class="cos-badge"><strong>КОД</strong>&nbsp; main</span>
  <span class="cos-badge"><strong>ДОКУМЕНТАЦІЯ</strong>&nbsp; main</span>
  <span class="cos-badge"><strong>МОДЕЛЬ</strong>&nbsp; Бізнес → процес → домен → виконання → код</span>
</div>

Карта читається зверху вниз. Починайте з бізнес-питання і переходьте до точних фактів виконуваного коду лише тоді, коли вони справді потрібні.

> **Виконуваний граф архітектури:** інтерактивна карта доступна в COS за маршрутом [`/cos/architecture`](https://company-os.shop/cos/architecture). Ця сторінка пояснює ментальну модель, а [згенерований граф архітектури](../12-reference/architecture-graph.md) містить точну машинну проєкцію.

<div class="cos-system-map">
  <div class="cos-map-layer">
    <div class="cos-map-title">1 · Бізнес-процеси</div>
    <div class="cos-map-grid">
      <a class="cos-map-node" href="../02-workflows/sales-lead-to-managed-case.html"><strong>Продажі (Sales)</strong><span>Звернення → керована справа → воронка → наступна дія → результат.</span></a>
      <a class="cos-map-node" href="../02-workflows/property-submission-to-publication.html"><strong>Нерухомість (Property)</strong><span>Надходження об’єкта → канонічний актив → комерційний облік → оголошення → публікація.</span></a>
      <a class="cos-map-node" href="../02-workflows/diagnostic-session-to-recommendation.html"><strong>Діагностика (Diagnostic)</strong><span>Методологія → факти → оцінювання → рекомендація.</span></a>
    </div>
  </div>

  <div class="cos-map-flow">↓</div>

  <div class="cos-map-layer">
    <div class="cos-map-title">2 · Бізнес-домени</div>
    <div class="cos-map-grid">
      <a class="cos-map-node" href="../04-domains/sales/overview.html"><strong>Продажі (Sales)</strong><span>Попит, воронка, активності, автоматизація та взаємодія з CRM.</span></a>
      <a class="cos-map-node" href="../04-domains/property/overview.html"><strong>Нерухомість (Property)</strong><span>Реєстр активів, комерційний облік, оголошення, публікації, мережа й аналітичний інтелект.</span></a>
      <a class="cos-map-node" href="../04-domains/diagnostic/overview.html"><strong>Діагностика (Diagnostic)</strong><span>Методологія, факти, детерміноване оцінювання та рекомендації.</span></a>
      <a class="cos-map-node" href="../04-domains/supporting-domains.html"><strong>Допоміжні домени</strong><span>Інші обмежені контексти та поточні межі відповідальності.</span></a>
    </div>
  </div>

  <div class="cos-map-flow">↓</div>

  <div class="cos-map-layer">
    <div class="cos-map-title">3 · Ядро та середовище виконання</div>
    <div class="cos-map-grid">
      <a class="cos-map-node is-kernel" href="./kernel-overview.html"><strong>Ядро (Kernel)</strong><span>Універсальні механізми без бізнес-словника конкретного домену.</span></a>
      <a class="cos-map-node is-kernel" href="../05-runtime/execution-lifecycle.html"><strong>Виконання</strong><span>Намір або факт → рішення → повноваження → виконання → результат.</span></a>
      <a class="cos-map-node is-kernel" href="../05-runtime/events-and-outbox.html"><strong>Події та Outbox</strong><span>Факти, надійна доставка та транзакційна межа.</span></a>
      <a class="cos-map-node is-kernel" href="../05-runtime/policies-and-approvals.html"><strong>Політики та погодження</strong><span>Автоматичні, погоджувані та заборонені зміни.</span></a>
      <a class="cos-map-node is-kernel" href="../06-ai-agents/agent-runtime.html"><strong>Середовище виконання агентів</strong><span>Контекст → пропозиція → політика; без прихованої прямої зміни стану.</span></a>
    </div>
  </div>

  <div class="cos-map-flow">↓</div>

  <div class="cos-map-layer">
    <div class="cos-map-title">4 · Контракти, інтерфейси та інфраструктура</div>
    <div class="cos-map-grid">
      <a class="cos-map-node" href="./cross-domain-contracts.html"><strong>Міждоменні контракти</strong><span>Взаємодія між обмеженими контекстами без порушення відповідальності.</span></a>
      <a class="cos-map-node" href="../07-api-integrations/integration-model.html"><strong>Інтеграції</strong><span>Адаптери постачальників, API та зовнішні межі.</span></a>
      <a class="cos-map-node" href="../08-ui/interface-surfaces.html"><strong>Поверхні інтерфейсу</strong><span>Портал, робочий простір та операційні інтерфейси.</span></a>
      <a class="cos-map-node" href="../00-start/repository-map.html"><strong>Карта репозиторію</strong><span>Де архітектурні частини розташовані в коді.</span></a>
    </div>
  </div>

  <div class="cos-map-flow">↓</div>

  <div class="cos-map-layer">
    <div class="cos-map-title">5 · Точний технічний довідник</div>
    <div class="cos-map-grid">
      <a class="cos-map-node" href="../12-reference/module-capabilities.html"><strong>Модулі та можливості</strong><span>Точні декларації та можливості поточного коду.</span></a>
      <a class="cos-map-node" href="../12-reference/application-use-cases.html"><strong>Варіанти використання</strong><span>Виконувані точки входу рівня застосунку.</span></a>
      <a class="cos-map-node" href="../12-reference/event-types.html"><strong>Події</strong><span>Канонічний перелік типів подій.</span></a>
      <a class="cos-map-node" href="../12-reference/commands.html"><strong>Команди</strong><span>Канонічний перелік команд.</span></a>
      <a class="cos-map-node" href="../12-reference/module-routes.html"><strong>Маршрути</strong><span>Маршрути, які вносять модулі.</span></a>
      <a class="cos-map-node" href="../12-reference/architecture-graph.html"><strong>Граф архітектури</strong><span>Канонічні типи вузлів, зв’язки та проєкції.</span></a>
      <a class="cos-map-node" href="../12-reference/database.html"><strong>База даних</strong><span>Міграції та визначене володіння таблицями.</span></a>
      <a class="cos-map-node" href="../12-reference/configuration.html"><strong>Конфігурація</strong><span>Постачальники конфігурації та декларації можливостей.</span></a>
      <a class="cos-map-node" href="../12-reference/errors-and-failures.html"><strong>Помилки та відмови</strong><span>Класифікація відмов і відповідні реалізації.</span></a>
    </div>
  </div>
</div>

## Канонічні маршрути заглиблення

### Продажі

[Бізнес-процес](../02-workflows/sales-lead-to-managed-case.md) → [огляд](../04-domains/sales/overview.md) → [модель домену](../04-domains/sales/domain-model.md) → [життєвий цикл та автоматизація](../04-domains/sales/lifecycle-and-automation.md) → [контракти й код](../04-domains/sales/contracts-and-code-map.md) → [точний довідник](../12-reference/application-use-cases.md)

### Нерухомість

[Бізнес-процес](../02-workflows/property-submission-to-publication.md) → [огляд](../04-domains/property/overview.md) → [модель домену](../04-domains/property/domain-model.md) → [життєвий цикл і виконання](../04-domains/property/lifecycle-and-runtime.md) → [контракти й код](../04-domains/property/contracts-and-code-map.md) → [точний довідник](../12-reference/module-capabilities.md)

### Діагностика

[Бізнес-процес](../02-workflows/diagnostic-session-to-recommendation.md) → [огляд](../04-domains/diagnostic/overview.md) → [модель домену](../04-domains/diagnostic/domain-model.md) → [життєвий цикл та оцінювання](../04-domains/diagnostic/lifecycle-and-evaluation.md) → [контракти й код](../04-domains/diagnostic/contracts-and-code-map.md) → [точний довідник](../12-reference/application-use-cases.md)

## Джерело правди

Згенеровані факти синхронізуються з поточним `main` під час збірки. Пояснювальна карта системи не дублює реєстр графа архітектури: виконувану архітектуру можна переглядати через `/cos/architecture`, а точний каталог понять і проєкцій генерується в технічному довіднику.
