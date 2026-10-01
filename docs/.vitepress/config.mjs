import { buildSidebar } from './sidebar.mjs';
import { LOCALES, getLocale, localizedRoute, localizedUrl } from './locales.mjs';
import { buildSystemStatus } from './system-status.mjs';
import { installMermaidMarkdown } from './mermaid-markdown.mjs';

const docsBase = process.env.COS_DOCS_BASE || '/docs/';
const cosSystemStatus = buildSystemStatus();

function searchFor(locale) {
  if (locale.id !== 'uk') return { provider: 'local' };

  return {
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
}

function themeFor(locale) {
  return {
    siteTitle: 'COS',
    nav: [
      { text: locale.ui.business, link: localizedUrl(locale.id, 'for-business') },
      { text: locale.ui.implementation, link: localizedUrl(locale.id, 'for-integrators') },
      { text: locale.ui.developers, link: localizedUrl(locale.id, 'for-developers') },
      { text: locale.ui.github, link: 'https://github.com/Murakhovsky/TerraNova2026/tree/main' },
    ],
    sidebar: buildSidebar(locale.id),
    sidebarMenuLabel: locale.ui.sidebarMenuLabel,
    returnToTopLabel: locale.ui.returnToTopLabel,
    darkModeSwitchLabel: locale.ui.darkModeSwitchLabel,
    langMenuLabel: locale.ui.langMenuLabel,
    outline: { level: [2, 3], label: locale.ui.outlineLabel },
    search: searchFor(locale),
    editLink: {
      pattern: 'https://github.com/Murakhovsky/TerraNova2026/edit/main/docs/:path',
      text: locale.ui.editPage,
    },
    lastUpdated: { text: locale.ui.updated, formatOptions: { dateStyle: 'medium', timeStyle: 'short' } },
    docFooter: { prev: locale.ui.previous, next: locale.ui.next },
    socialLinks: [{ icon: 'github', link: 'https://github.com/Murakhovsky/TerraNova2026/tree/main' }],
    footer: {
      message: locale.ui.footer,
      copyright: 'Terra Nova · COS',
    },
  };
}

const vitepressLocales = Object.fromEntries(
  LOCALES.map((locale) => [
    locale.viteKey,
    {
      label: locale.label,
      lang: locale.lang,
      ...(locale.prefix ? { link: '/' + locale.prefix + '/' } : {}),
      title: 'COS',
      description: locale.description,
      themeConfig: themeFor(locale),
    },
  ]),
);

export default {
  title: 'COS',
  description: 'COS documentation.',
  base: docsBase,
  outDir: '../public/docs',
  cleanUrls: false,
  lastUpdated: true,
  appearance: true,
  locales: vitepressLocales,
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
      getLocale(targetLocale);
      return localizedRoute(route.data.relativePath, targetLocale);
    },
  },
};
