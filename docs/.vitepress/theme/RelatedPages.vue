<script setup>
import { computed } from 'vue';
import { useData, withBase } from 'vitepress';

const { page } = useData();

const item = (text, link, description) => ({ text, link, description });

const domains = {
  sales: { label: 'Продажі', workflow: '/02-workflows/sales-lead-to-managed-case', lifecycle: 'lifecycle-and-automation' },
  property: { label: 'Нерухомість', workflow: '/02-workflows/property-submission-to-publication', lifecycle: 'lifecycle-and-runtime' },
  diagnostic: { label: 'Діагностика', workflow: '/02-workflows/diagnostic-session-to-recommendation', lifecycle: 'lifecycle-and-evaluation' },
};

const workflowDomains = {
  '02-workflows/sales-lead-to-managed-case.md': 'sales',
  '02-workflows/property-submission-to-publication.md': 'property',
  '02-workflows/diagnostic-session-to-recommendation.md': 'diagnostic',
};

const businessPages = [
  item('COS для бізнесу', '/for-business/', 'Коротко про продукт, проблему та користь для компанії.'),
  item('Що COS дає компанії', '/for-business/capabilities', 'Практичні можливості без технічної мови.'),
  item('Сценарії використання', '/for-business/use-cases', 'Продажі, нерухомість, діагностика та інші напрями.'),
  item('Як відбувається впровадження', '/for-business/implementation', 'Від бізнес-проблеми до перевіреного робочого процесу.'),
  item('Часті запитання', '/for-business/faq', 'CRM, ШІ, інтеграції, готовність продукту та межі застосування.'),
];

const integratorPages = [
  item('Маршрут впровадження', '/for-integrators/', 'Загальна послідовність упровадження COS у компанії.'),
  item('Дослідження процесу', '/for-integrators/discovery', 'Мета, учасники, факти, рішення, винятки та показники.'),
  item('Дані та інтеграції', '/for-integrators/data-and-integrations', 'Першоджерела, обмін, перенесення, доступ і надійність.'),
  item('Автоматизація і ШІ', '/for-integrators/automation-and-ai', 'Рівні повноважень і правила контрольованого виконання.'),
  item('Перевірка готовності', '/for-integrators/readiness', 'Контрольний список перед запуском у робочу експлуатацію.'),
];

function domainItems(domain) {
  const meta = domains[domain];
  const root = `/04-domains/${domain}`;
  return [
    item(`Огляд домену «${meta.label}»`, `${root}/overview`, 'Призначення, відповідальність і поточний технічний стан.'),
    item('Модель домену', `${root}/domain-model`, 'Канонічний словник, сутності та інваріанти.'),
    item('Життєвий цикл', `${root}/${meta.lifecycle}`, 'Переходи станів і шлях виконання.'),
    item('Контракти й код', `${root}/contracts-and-code-map`, 'Порти, адаптери, межі та карта коду.'),
    item('Бізнес-процес', meta.workflow, 'Наскрізний процес через систему.'),
    item('Карта системи', '/03-architecture/system-map', 'Повернутися до загальної карти архітектури.'),
  ];
}

function currentRoute(relativePath) {
  const normalized = relativePath.replace(/\.md$/, '');
  return normalized.endsWith('/index') ? `/${normalized.slice(0, -6)}/` : `/${normalized}`;
}

function pageHref(link) {
  const target = /(?:\.html|\/)$/.test(link) ? link : `${link}.html`;
  return withBase(target);
}

function withoutCurrent(entries, route) {
  return entries.filter((entry) => entry.link !== route).slice(0, 4);
}

const related = computed(() => {
  const relativePath = page.value.relativePath || '';
  if (relativePath.startsWith('en/')) return [];

  const route = currentRoute(relativePath);

  if (relativePath.startsWith('for-business/')) return withoutCurrent(businessPages, route);
  if (relativePath.startsWith('for-integrators/')) return withoutCurrent(integratorPages, route);

  const domainMatch = relativePath.match(/^04-domains\/(sales|property|diagnostic)\//);
  if (domainMatch) return withoutCurrent(domainItems(domainMatch[1]), route);

  if (relativePath === '02-workflows/business-process-modeling.md') {
    return withoutCurrent([
      item('Звернення → керована справа', domains.sales.workflow, 'Поточний процес приймання звернення, роботи з воронкою та контрольованої автоматизації.'),
      item('Об’єкт → публікація', domains.property.workflow, 'Поточний процес від об’єкта нерухомості до комерційної пропозиції та публікації.'),
      item('Діагностика → рекомендація', domains.diagnostic.workflow, 'Поточний процес методології, фактів, оцінювання та рекомендацій.'),
      item('Додавання бізнес-процесу', '/09-development/adding-a-workflow', 'Шлях розробника для додавання та перевірки канонічного процесу.'),
    ], route);
  }

  const workflowDomain = workflowDomains[relativePath];
  if (workflowDomain) {
    const meta = domains[workflowDomain];
    const root = `/04-domains/${workflowDomain}`;
    return withoutCurrent([
      item('Моделювання бізнес-процесів', '/02-workflows/business-process-modeling', 'Правила діаграм і стани правдивості поточного та цільового процесу.'),
      item(`Домен «${meta.label}»`, `${root}/overview`, 'Семантичний власник стану цього процесу.'),
      item('Модель домену', `${root}/domain-model`, 'Словник та інваріанти, на яких побудований процес.'),
      item('Життєвий цикл домену', `${root}/${meta.lifecycle}`, 'Переходи станів, автоматизація та виконання.'),
    ], route);
  }

  if (relativePath.startsWith('01-product/')) {
    return withoutCurrent([
      item('Бачення та принципи', '/01-product/vision-and-principles', 'Навіщо існує COS і які продуктові принципи є незмінними.'),
      item('Можливості продукту', '/01-product/capabilities', 'Що система дає на рівні бізнесу, виконання та платформи.'),
      item('Учасники та повноваження', '/01-product/actors-and-authority', 'Модель повноважень людей, автоматизації, агентів та інтеграцій.'),
      item('Межі системи', '/01-product/system-boundaries', 'За що відповідають COS, домени, інтерфейси та адаптери.'),
      item('Поточний стан', '/01-product/current-scope', 'Фактичний стан реалізації у поточній гілці main.'),
    ], route);
  }

  if (relativePath.startsWith('05-runtime/')) {
    return withoutCurrent([
      item('Ментальна модель COS', '/00-start/mental-model', 'Шлях від бізнес-наміру до контрольованого результату.'),
      item('Огляд ядра', '/03-architecture/kernel-overview', 'Універсальні механізми та межі ядра.'),
      item('Карта системи', '/03-architecture/system-map', 'Навігація від бізнес-процесів до точного технічного довідника.'),
      item('Виконання агентів', '/06-ai-agents/agent-runtime', 'Як рішення за участю ШІ входять у контрольоване виконання.'),
    ], route);
  }

  if (relativePath.startsWith('06-ai-agents/')) {
    return withoutCurrent([
      item('Виконання агентів', '/06-ai-agents/agent-runtime', 'Канонічний шлях виконання агента.'),
      item('Контекст та інструменти', '/06-ai-agents/context-and-tools', 'Формування контексту та контрольовані межі інструментів.'),
      item('Модель пам’яті', '/06-ai-agents/memory-model', 'Факти, робочий контекст, пошук та довготривала пам’ять.'),
      item('Оцінювання агентів', '/06-ai-agents/agent-evaluation', 'Якість рішень, безпека, надійність і користь для бізнесу.'),
      item('Керування мовними моделями', '/06-ai-agents/llm-governance', 'Постачальники, повноваження, приватність і обмеження.'),
      item('Додавання агента', '/09-development/adding-an-agent', 'Шлях розробника для контрольованого агента.'),
    ], route);
  }

  if (relativePath.startsWith('07-api-integrations/')) {
    return withoutCurrent([
      item('Модель інтеграцій', '/07-api-integrations/integration-model', 'Правила портів, адаптерів і меж зовнішніх постачальників.'),
      item('API та вебхуки', '/07-api-integrations/api-and-webhooks', 'Передача HTTP, перевірка, повторюваність і передавання в домен.'),
      item('Канали повідомлень', '/07-api-integrations/messaging-channels', 'Telegram та майбутні канали через спільні межі застосунку.'),
      item('Надійність зовнішніх систем', '/07-api-integrations/external-reliability', 'Повторні спроби, строки очікування, помилки й кореляція.'),
      item('Додавання інтеграції', '/09-development/adding-an-integration', 'Шлях розробника для безпечної інтеграції.'),
    ], route);
  }

  if (relativePath.startsWith('08-ui/')) {
    return withoutCurrent([
      item('Поверхні інтерфейсу', '/08-ui/interface-surfaces', 'Веб, API, Telegram і командний рядок.'),
      item('Модель робочого простору', '/08-ui/workspace-model', 'Операційний контекст, читання даних і дії доменів.'),
      item('Навігація та дозволи', '/08-ui/navigation-and-permissions', 'Модульна навігація без змішування з моделлю безпеки.'),
      item('Інтерфейс документації', '/08-ui/documentation-site', 'Документація як окремий продуктовий інтерфейс.'),
    ], route);
  }

  if (relativePath.startsWith('09-development/')) {
    return withoutCurrent([
      item('Маршрути читання', '/00-start/reading-paths', 'Найкоротший шлях для розробки або архітектурної роботи.'),
      item('Локальний запуск', '/09-development/local-setup', 'Запуск системи та документації локально.'),
      item('Тестування', '/09-development/testing', 'Архітектурні, інтеграційні, інтерфейсні та документаційні перевірки.'),
      item('Правила документації', '/09-development/documentation-rules', 'Стани правдивості, типи сторінок і згенерований довідник.'),
      item('Карта системи', '/03-architecture/system-map', 'Архітектурна межа, яку ви змінюєте.'),
    ], route);
  }

  if (relativePath.startsWith('10-operations/')) {
    return withoutCurrent([
      item('Розгортання та працездатність', '/10-operations/deployment-and-health', 'Узгоджене з комітом розгортання та перевірка працездатності.'),
      item('Готовність модулів', '/10-operations/module-readiness', 'Перевірка операційної готовності модулів.'),
      item('Дані та міграції', '/10-operations/data-and-migrations', 'Життєвий цикл схеми та міграцій.'),
      item('Спостереження та інциденти', '/10-operations/observability-and-incidents', 'Журнали, показники, кореляція та розбір інцидентів.'),
      item('Резервне копіювання та відновлення', '/10-operations/backup-and-recovery', 'Відновлення основного стану та похідних представлень.'),
      item('Експлуатаційна безпека', '/10-operations/security-operations', 'Ізоляція, секрети, повноваження та реагування на інциденти.'),
    ], route);
  }

  if (relativePath.startsWith('00-start/')) {
    return withoutCurrent([
      item('Ментальна модель COS', '/00-start/mental-model', 'Модель наміру, відповідальності та виконання.'),
      item('Маршрути читання', '/00-start/reading-paths', 'Як зрозуміти, розробляти або експлуатувати COS без читання всього підряд.'),
      item('Бачення продукту', '/01-product/vision-and-principles', 'Навіщо існує система та які принципи вона не повинна порушувати.'),
      item('Карта системи', '/03-architecture/system-map', 'Інтерактивний огляд архітектури.'),
      item('Карта репозиторію', '/00-start/repository-map', 'Де частини системи розташовані в коді.'),
    ], route);
  }

  if (relativePath.startsWith('03-architecture/')) {
    return withoutCurrent([
      item('Ментальна модель COS', '/00-start/mental-model', 'Почніть із наміру, відповідальності та виконання.'),
      item('Карта системи', '/03-architecture/system-map', 'Загальний огляд системи.'),
      item('Карта доменів', '/03-architecture/domain-map', 'Поточні взаємозв’язки предметних областей.'),
      item('Карта репозиторію', '/00-start/repository-map', 'Перехід від архітектури до структури коду.'),
      item('Життєвий цикл виконання', '/05-runtime/execution-lifecycle', 'Правила виконання за архітектурними межами.'),
    ], route);
  }

  return [];
});
</script>

<template>
  <div v-if="related.length" class="cos-system-map" aria-label="Пов’язана документація">
    <div class="cos-map-layer">
      <div class="cos-map-title">Пов’язані сторінки</div>
      <div class="cos-map-grid">
        <a v-for="entry in related" :key="entry.link" class="cos-map-node" :href="pageHref(entry.link)">
          <strong>{{ entry.text }}</strong>
          <span>{{ entry.description }}</span>
        </a>
      </div>
    </div>
  </div>
</template>