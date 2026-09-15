import { buildSidebar, buildEnglishSidebar } from './sidebar.mjs';
import { buildSystemStatus } from './system-status.mjs';
import { installMermaidMarkdown } from './mermaid-markdown.mjs';

const docsBase = process.env.COS_DOCS_BASE || '/docs/';
const cosSystemStatus = buildSystemStatus();

const ukrainianSearch = {
  provider: 'local',
  options: {
    translations: {
      button: {
        buttonText: 'Пошук',
        buttonAriaLabel: 'Пошук у документації',
      },
      modal: {
        noResultsText: 'Нічого не знайдено',
        resetButtonTitle: 'Очистити',
        footer: {
          selectText: 'вибрати',
          navigateText: 'перейти',
          closeText: 'закрити',
        },
      },
    },
  },
};

const ukrainianTheme = {
  siteTitle: 'COS',
  nav: [
    { text: 'Для бізнесу', link: '/for-business/' },
    { text: 'Для впровадження', link: '/for-integrators/' },
    { text: 'Для розробників', link: '/for-developers/' },
    { text: 'Стан системи', link: '/01-product/current-scope' },
    { text: 'GitHub', link: 'https://github.com/Murakhovsky/TerraNova2026/tree/main' },
  ],
  sidebar: buildSidebar(),
  sidebarMenuLabel: 'Навігація',
  returnToTopLabel: 'На початок',
  darkModeSwitchLabel: 'Тема',
  langMenuLabel: 'Змінити мову',
  outline: { level: [2, 3], label: 'На цій сторінці' },
  search: ukrainianSearch,
  editLink: {
    pattern: 'https://github.com/Murakhovsky/TerraNova2026/edit/main/docs/:path',
    text: 'Редагувати сторінку',
  },
  lastUpdated: { text: 'Оновлено', formatOptions: { dateStyle: 'medium', timeStyle: 'short' } },
  docFooter: { prev: 'Попередня сторінка', next: 'Наступна сторінка' },
  socialLinks: [{ icon: 'github', link: 'https://github.com/Murakhovsky/TerraNova2026/tree/main' }],
  footer: {
    message: 'Канонічна гілка: main · Джерело правди: поточний код, тести, декларації та документація.',
    copyright: 'Terra Nova · COS',
  },
};

const englishTheme = {
  siteTitle: 'COS',
  nav: [
    { text: 'For business', link: '/en/for-business/' },
    { text: 'For implementation', link: '/en/for-integrators/' },
    { text: 'For developers', link: '/en/for-developers/' },
    { text: 'GitHub', link: 'https://github.com/Murakhovsky/TerraNova2026/tree/main' },
  ],
  sidebar: buildEnglishSidebar(),
  sidebarMenuLabel: 'Navigation',
  returnToTopLabel: 'Back to top',
  darkModeSwitchLabel: 'Theme',
  langMenuLabel: 'Change language',
  outline: { level: [2, 3], label: 'On this page' },
  search: { provider: 'local' },
  editLink: {
    pattern: 'https://github.com/Murakhovsky/TerraNova2026/edit/main/docs/:path',
    text: 'Edit this page',
  },
  lastUpdated: { text: 'Updated', formatOptions: { dateStyle: 'medium', timeStyle: 'short' } },
  docFooter: { prev: 'Previous page', next: 'Next page' },
  socialLinks: [{ icon: 'github', link: 'https://github.com/Murakhovsky/TerraNova2026/tree/main' }],
  footer: {
    message: 'Canonical branch: main · Source of truth: current code, tests, manifests and documentation.',
    copyright: 'Terra Nova · COS',
  },
};

export default {
  title: 'COS',
  description: 'Документація операційної системи компанії COS.',
  base: docsBase,
  outDir: '../public/docs',
  cleanUrls: false,
  lastUpdated: true,
  appearance: true,
  locales: {
    root: {
      label: 'Українська',
      lang: 'uk-UA',
      title: 'COS',
      description: 'Операційна система компанії: можливості, впровадження та розробка.',
      themeConfig: ukrainianTheme,
    },
    en: {
      label: 'English',
      lang: 'en-US',
      link: '/en/',
      title: 'COS',
      description: 'Company Operating System: product, implementation and development documentation.',
      themeConfig: englishTheme,
    },
  },
  markdown: {
    lineNumbers: true,
    config(md) {
      installMermaidMarkdown(md);
    },
  },
  head: [
    ['meta', { name: 'theme-color', content: '#07110f' }],
    ['meta', { name: 'color-scheme', content: 'dark light' }],
    ['meta', { name: 'viewport', content: 'width=device-width, initial-scale=1, viewport-fit=cover' }],
  ],
  themeConfig: {
    cosSystemStatus,
    i18nRouting(data, route, targetLocale) {
      const relativePath = route.data.relativePath.replace(/\.md$/, '');
      if (targetLocale === 'en') {
        if (relativePath === 'index') return '/en/';
        if (/^for-(business|integrators|developers)\//.test(relativePath)) {
          return `/en/${relativePath}/`;
        }
        return '/en/for-developers/';
      }

      if (relativePath === 'en/index') return '/';
      if (relativePath.startsWith('en/')) {
        return `/${relativePath.slice(3)}/`;
      }
      return `/${relativePath}/`;
    },
  },
};
