---
title: Можливості COS
description: Модель можливостей COS від бізнес-процесів до керованої автоматизації та інтеграцій.
status: active
updated: 2026-09-16
kind: product
---

# Можливості COS

Можливість (capability) тут означає **що система дозволяє бізнесу робити**, а не назву PHP-сервісу або окремої кнопки в інтерфейсі.

## Рівні можливостей

<div class="cos-system-map">
  <div class="cos-map-layer">
    <div class="cos-map-title">Бізнес-можливості</div>
    <div class="cos-map-grid">
      <a class="cos-map-node" href="../04-domains/sales/overview.html"><strong>Операції продажів</strong><span>Приймання попиту, справи та угоди, воронка, активності, наступні дії та результати.</span></a>
      <a class="cos-map-node" href="../04-domains/property/overview.html"><strong>Операції з нерухомістю</strong><span>Канонічні активи, комерційний облік, оголошення, публікації та зовнішня мережа.</span></a>
      <a class="cos-map-node" href="../04-domains/diagnostic/overview.html"><strong>Діагностика бізнесу</strong><span>Методологія, докази, оцінювання, знахідки та рекомендації.</span></a>
    </div>
  </div>

  <div class="cos-map-layer">
    <div class="cos-map-title">Можливості операційної системи</div>
    <div class="cos-map-grid">
      <a class="cos-map-node is-kernel" href="../05-runtime/execution-lifecycle.html"><strong>Кероване виконання</strong><span>Команди й дії, політики, погодження, черги та результати.</span></a>
      <a class="cos-map-node is-kernel" href="../05-runtime/events-and-outbox.html"><strong>Робота за подіями</strong><span>Бізнес-факти, надійна доставка та запуск автоматизації.</span></a>
      <a class="cos-map-node is-kernel" href="../05-runtime/audit-and-diagnostics.html"><strong>Аудит і спостережуваність</strong><span>Відновлення того, що сталося, чому, де і з яким результатом.</span></a>
      <a class="cos-map-node" href="../06-ai-agents/agent-runtime.html"><strong>Рішення за участю агентів</strong><span>Структуровані пропозиції на контексті домену під явним контролем повноважень.</span></a>
    </div>
  </div>

  <div class="cos-map-layer">
    <div class="cos-map-title">Платформні можливості</div>
    <div class="cos-map-grid">
      <a class="cos-map-node" href="../03-architecture/extension-runtime.html"><strong>Модульне середовище виконання</strong><span>Встановлювані внески модулів та активація для організацій без прив’язки ядра до бізнес-семантики.</span></a>
      <a class="cos-map-node" href="../07-api-integrations/integration-model.html"><strong>Інтеграції</strong><span>Зовнішні системи за канонічними портами, адаптерами та перекладом словника.</span></a>
      <a class="cos-map-node" href="../12-reference/permissions-capabilities.html"><strong>Повноваження та дозволи</strong><span>Можливості й межі прав для виконання операцій.</span></a>
      <a class="cos-map-node" href="../08-ui/interface-surfaces.html"><strong>Операційні інтерфейси</strong><span>Робочі простори для людей, які відкривають операції доменів, але не володіють їхніми правилами.</span></a>
    </div>
  </div>
</div>

## Поточний стан і напрям продукту

Не кожна можливість має однаковий рівень зрілості. [Поточний стан](./current-scope.md) є канонічним людським зрізом того, що реально існує зараз. Ця сторінка пояснює продуктову модель і спосіб композиції можливостей.

## Композиція можливостей

Один бізнес-процес може використовувати кілька можливостей одночасно:

```text
Подія продажів
→ рішення за участю агента
→ політика / дозвіл
→ погодження, якщо потрібне
→ варіант використання Sales
→ зовнішній адаптер CRM
→ подія результату
→ аудит
```

Саме композиція спільних механізмів із доменною логікою, а не дублювання одних і тих самих функцій у кожному домені, робить COS операційною системою, а не колекцією окремих застосунків.

## Практичне правило

Якщо нова можливість має власний бізнес-смисл, стан, правила та життєвий цикл, вона належить відповідному домену. Якщо вона лише забезпечує універсальний спосіб виконання, доставки, погодження, аудиту чи інтеграції, її місце ближче до ядра або спільної платформи.
