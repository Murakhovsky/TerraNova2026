import { buildSidebar } from './sidebar.mjs';
import { buildSystemStatus } from './system-status.mjs';
import { installMermaidMarkdown } from './mermaid-markdown.mjs';

const docsBase = process.env.COS_DOCS_BASE || '/docs/';
const cosSystemStatus = buildSystemStatus();

export default {
  title: 'COS Documentation',
  description: 'Company Operating System product, architecture, runtime and development documentation.',
  lang: 'uk-UA',
  base: docsBase,
  outDir: '../public/docs',
  cleanUrls: false,
  lastUpdated: true,
  appearance: true,
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
    siteTitle: 'COS Documentation',
    cosSystemStatus,
    nav: [
      { text: 'Start', link: '/00-start/what-is-cos' },
      { text: 'Current State', link: '/01-product/current-scope' },
      { text: 'Workflows', link: '/02-workflows/business-process-modeling' },
      { text: 'Architecture', link: '/03-architecture/system-map' },
      { text: 'Domains', link: '/04-domains/sales/overview' },
      { text: 'Reference', link: '/12-reference/README' },
      { text: 'main', link: 'https://github.com/Murakhovsky/TerraNova2026/tree/main' },
    ],
    sidebar: buildSidebar(),
    sidebarMenuLabel: 'Навігація',
    returnToTopLabel: 'На початок',
    darkModeSwitchLabel: 'Тема',
    outline: { level: [2, 3], label: 'На цій сторінці' },
    search: {
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
    },
    editLink: {
      pattern: 'https://github.com/Murakhovsky/TerraNova2026/edit/main/docs/:path',
      text: 'Редагувати документацію',
    },
    lastUpdated: { text: 'Оновлено', formatOptions: { dateStyle: 'medium', timeStyle: 'short' } },
    docFooter: { prev: 'Попередня сторінка', next: 'Наступна сторінка' },
    socialLinks: [{ icon: 'github', link: 'https://github.com/Murakhovsky/TerraNova2026/tree/main' }],
    footer: {
      message: 'Canonical branch: main · Source of truth: code + tests + manifests in the current commit.',
      copyright: 'Terra Nova · Company Operating System',
    },
  },
};
