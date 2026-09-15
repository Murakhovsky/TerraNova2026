---
layout: home
title: COS Documentation
description: Канонічна WEB-документація Company Operating System.
hero:
  name: Company Operating System
  text: Documentation
  tagline: Від бізнес-процесу до Domain, Runtime, Contract, Agent і коду. Один простір для продукту, архітектури та executable reference.
  actions:
    - theme: brand
      text: Зрозуміти COS за 10 хв
      link: /00-start/what-is-cos
    - theme: alt
      text: Відкрити System Map
      link: /03-architecture/system-map
features:
  - title: Understand COS
    details: Product model, Mental Model, current scope, workflows та bounded contexts без походів по сотнях PHP-файлів.
    link: /00-start/what-is-cos
  - title: Business Workflows
    details: Sales, Property і Diagnostic від business goal до decisions, failures та code map.
    link: /02-workflows/sales-lead-to-managed-case
  - title: Architecture
    details: Kernel, Domains, cross-domain contracts, runtime, persistence та dependency direction.
    link: /03-architecture/domain-map
  - title: Build with COS
    details: Development rules, generated reference, module lifecycle, testing та operations.
    link: /09-development/adding-a-domain
---

<div class="cos-home-shell">
  <div class="cos-home-contract" aria-label="Canonical source contract">
    <div class="cos-home-contract__item"><span>Canonical code</span><strong>main</strong></div>
    <div class="cos-home-contract__item"><span>Canonical docs</span><strong>main</strong></div>
    <div class="cos-home-contract__item"><span>Authority</span><strong>current commit</strong></div>
  </div>

  <section class="cos-home-section">
    <div class="cos-home-section__head">
      <div>
        <div class="cos-home-kicker">System model</div>
        <h2>Від проблеми бізнесу до контрольованого результату</h2>
      </div>
      <p>COS не ховає бізнес за технічними шарами. Документація веде тією самою дорогою, якою проходить реальна робота системи.</p>
    </div>

    <div class="cos-home-flow" aria-label="COS canonical execution path">
      <div class="cos-home-flow__node"><strong>Business</strong><span>problem / goal</span></div>
      <div class="cos-home-flow__arrow">→</div>
      <div class="cos-home-flow__node"><strong>Workflow</strong><span>business flow</span></div>
      <div class="cos-home-flow__arrow">→</div>
      <div class="cos-home-flow__node"><strong>Domain</strong><span>semantic owner</span></div>
      <div class="cos-home-flow__arrow">→</div>
      <div class="cos-home-flow__node is-core"><strong>Kernel Runtime</strong><span>generic mechanism</span></div>
      <div class="cos-home-flow__arrow">→</div>
      <div class="cos-home-flow__node"><strong>Result</strong><span>state / audit</span></div>
    </div>
  </section>

  <section class="cos-home-section">
    <div class="cos-home-section__head">
      <div>
        <div class="cos-home-kicker">Current baseline</div>
        <h2>Що реально є в current checkout</h2>
      </div>
      <p>Цей блок більше не дублює runtime-версії руками. Kernel і Domain manifests читаються безпосередньо з коду під час VitePress build; exact capabilities, events, commands і routes залишаються у generated reference.</p>
    </div>

    <SystemStatus />
  </section>

  <section class="cos-home-section">
    <div class="cos-home-section__head">
      <div>
        <div class="cos-home-kicker">Choose a route</div>
        <h2>Не треба читати все</h2>
      </div>
      <p>Починайте з того рівня, який відповідає вашому питанню. Людство вже винайшло навігацію, тож археологія по Service.php більше не є обов’язковим ритуалом.</p>
    </div>

    <div class="cos-home-route-grid">
      <a class="cos-home-route" href="./00-start/mental-model.html">
        <small>01 / UNDERSTAND</small>
        <strong>Я хочу зрозуміти COS</strong>
        <span>Mental model, product scope, domains і ключові business workflows.</span>
      </a>
      <a class="cos-home-route" href="./03-architecture/system-map.html">
        <small>02 / ARCHITECT</small>
        <strong>Я хочу побачити систему цілком</strong>
        <span>System map, dependency direction, Kernel, contracts і runtime boundaries.</span>
      </a>
      <a class="cos-home-route" href="./12-reference/README.html">
        <small>03 / VERIFY</small>
        <strong>Мені потрібні точні executable facts</strong>
        <span>Modules, versions, capabilities, events, commands, routes та generated reference.</span>
      </a>
    </div>
  </section>

  <div class="cos-home-footer-note">
    <span>main = code + tests + manifests + docs + generated reference inputs</span>
    <span>COS Documentation · Terra Nova</span>
  </div>
</div>
