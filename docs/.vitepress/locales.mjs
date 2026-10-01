import { existsSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const docsRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');

export const DEFAULT_LOCALE = 'uk';

export const LOCALES = [
  {
    id: 'uk',
    viteKey: 'root',
    prefix: '',
    label: 'Українська',
    lang: 'uk-UA',
    description: 'Операційна система компанії: можливості, впровадження та розробка.',
    ui: {
      business: 'Для бізнесу',
      implementation: 'Впровадження',
      developers: 'Для розробників',
      technicalCorpus: 'Детальна технічна документація',
      github: 'GitHub',
      sidebarMenuLabel: 'Навігація',
      returnToTopLabel: 'На початок',
      darkModeSwitchLabel: 'Тема',
      langMenuLabel: 'Змінити мову',
      outlineLabel: 'На цій сторінці',
      editPage: 'Редагувати сторінку',
      updated: 'Оновлено',
      previous: 'Попередня сторінка',
      next: 'Наступна сторінка',
      footer: 'Джерело правди: поточний код, активні тести, декларації та документація.',
    },
  },
  {
    id: 'en',
    viteKey: 'en',
    prefix: 'en',
    label: 'English',
    lang: 'en-US',
    description: 'Company Operating System: business, implementation and developer documentation.',
    ui: {
      business: 'For business',
      implementation: 'Implementation',
      developers: 'For developers',
      technicalCorpus: 'Detailed technical documentation',
      github: 'GitHub',
      sidebarMenuLabel: 'Navigation',
      returnToTopLabel: 'Back to top',
      darkModeSwitchLabel: 'Theme',
      langMenuLabel: 'Change language',
      outlineLabel: 'On this page',
      editPage: 'Edit this page',
      updated: 'Updated',
      previous: 'Previous page',
      next: 'Next page',
      footer: 'Source of truth: current code, active tests, manifests and documentation.',
    },
  },
];

export const AUDIENCE_STRUCTURE = [
  {
    id: 'business',
    directory: 'for-business',
    labels: { uk: 'Для бізнесу та партнерів', en: 'For business and partners' },
    pages: [
      { slug: 'index', labels: { uk: 'Що таке COS', en: 'What COS is' } },
      { slug: 'capabilities', labels: { uk: 'Що COS дає компанії', en: 'What COS gives a company' } },
      { slug: 'use-cases', labels: { uk: 'Сценарії використання', en: 'Use cases' } },
      { slug: 'implementation', labels: { uk: 'Як відбувається впровадження', en: 'How implementation works' } },
      { slug: 'faq', labels: { uk: 'Часті запитання', en: 'FAQ' } },
    ],
  },
  {
    id: 'implementation',
    directory: 'for-integrators',
    labels: { uk: 'Для впровадження', en: 'For implementation' },
    pages: [
      { slug: 'index', labels: { uk: 'Маршрут впровадження', en: 'Implementation route' } },
      { slug: 'discovery', labels: { uk: 'Дослідження процесу', en: 'Process discovery' } },
      { slug: 'data-and-integrations', labels: { uk: 'Дані та інтеграції', en: 'Data and integrations' } },
      { slug: 'automation-and-ai', labels: { uk: 'Автоматизація і ШІ', en: 'Automation and AI' } },
      { slug: 'readiness', labels: { uk: 'Перевірка готовності', en: 'Readiness check' } },
    ],
  },
  {
    id: 'developer',
    directory: 'for-developers',
    labels: { uk: 'Для розробників', en: 'For developers' },
    pages: [
      { slug: 'index', labels: { uk: 'Вхід для розробника', en: 'Developer entry point' } },
      { slug: 'architecture', labels: { uk: 'Архітектура', en: 'Architecture' } },
      { slug: 'domains', labels: { uk: 'Домени та межі', en: 'Domains and boundaries' } },
      { slug: 'runtime', labels: { uk: 'Середовище виконання', en: 'Runtime' } },
      { slug: 'development', labels: { uk: 'Розробка і перевірка', en: 'Development and verification' } },
    ],
  },
];

export function getLocale(reference) {
  const locale = LOCALES.find((item) => item.id === reference || item.viteKey === reference);
  if (!locale) throw new Error('Unknown documentation locale: ' + reference);
  return locale;
}

export function translated(value, localeId) {
  return value?.[localeId] ?? value?.en ?? value?.uk ?? '';
}

export function localizedUrl(localeReference, semanticPath = '') {
  const locale = getLocale(localeReference);
  let clean = String(semanticPath).replace(/^\/+|\/+$/g, '').replace(/\.md$/, '');
  if (clean === 'index') clean = '';
  if (clean.endsWith('/index')) clean = clean.slice(0, -6);

  const prefix = locale.prefix ? '/' + locale.prefix : '';
  if (!clean) return (prefix || '') + '/';
  return prefix + '/' + clean;
}

export function localizedSourcePath(localeReference, semanticPath) {
  const locale = getLocale(localeReference);
  const clean = String(semanticPath).replace(/^\/+|\/+$/g, '').replace(/\.md$/, '') || 'index';
  const relativePath = (clean === 'index' ? 'index' : clean) + '.md';
  return locale.prefix
    ? resolve(docsRoot, locale.prefix, relativePath)
    : resolve(docsRoot, relativePath);
}

export function semanticPathFromRelative(relativePath) {
  let clean = String(relativePath).replace(/\\/g, '/').replace(/^\/+/, '').replace(/\.md$/, '');
  for (const locale of LOCALES) {
    if (!locale.prefix) continue;
    if (clean === locale.prefix) return 'index';
    if (clean.startsWith(locale.prefix + '/')) return clean.slice(locale.prefix.length + 1);
  }
  return clean || 'index';
}

function audienceForSemanticPath(semanticPath) {
  return AUDIENCE_STRUCTURE.find((audience) =>
    semanticPath === audience.directory || semanticPath.startsWith(audience.directory + '/')
  );
}

export function localizedRoute(relativePath, targetLocaleReference) {
  const target = getLocale(targetLocaleReference);
  const semanticPath = semanticPathFromRelative(relativePath);

  if (semanticPath === 'index') return localizedUrl(target.id);

  const targetSource = localizedSourcePath(target.id, semanticPath);
  if (existsSync(targetSource)) return localizedUrl(target.id, semanticPath);

  const audience = audienceForSemanticPath(semanticPath);
  if (audience) return localizedUrl(target.id, audience.directory);

  return localizedUrl(target.id, 'for-developers');
}
