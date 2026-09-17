import { buildSidebar } from './sidebar.mjs';
import { buildSystemStatus } from './system-status.mjs';
import { installMermaidMarkdown } from './mermaid-markdown.mjs';
import { buildI18nConfig } from './i18n.mjs';

const docsBase = process.env.COS_DOCS_BASE || '/docs/';
const cosSystemStatus = buildSystemStatus();
const cosI18n = buildI18nConfig();

export default {
  title: 'COS',
  description: 'Company Operating System: product, implementation and development documentation.',
  lang: 'en-US',
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
    cosSystemStatus,
    cosI18n,
    siteTitle: 'COS',
    nav: [
      { text: 'For business', link: '/for-business/' },
      { text: 'Capabilities', link: '/for-business/capabilities' },
      { text: 'For implementation', link: '/for-integrators/' },
      { text: 'For developers', link: '/for-developers/' },
      { text: 'GitHub', link: 'https://github.com/Murakhovsky/TerraNova2026/tree/main' },
    ],
    sidebar: buildSidebar('en'),
    sidebarMenuLabel: 'Navigation',
    returnToTopLabel: 'Back to top',
    darkModeSwitchLabel: 'Theme',
    outline: { level: [2, 3], label: 'On this page' },
    search: { provider: 'local' },
    editLink: {
      pattern: 'https://github.com/Murakhovsky/TerraNova2026/edit/documentation/docs/:path',
      text: 'Edit this page',
    },
    lastUpdated: { text: 'Updated', formatOptions: { dateStyle: 'medium', timeStyle: 'short' } },
    docFooter: { prev: 'Previous page', next: 'Next page' },
    socialLinks: [{ icon: 'github', link: 'https://github.com/Murakhovsky/TerraNova2026/tree/documentation' }],
    footer: {
      message: 'English is the default language · One canonical page structure · Locale selected with ?lang=<locale>.',
      copyright: 'Terra Nova · COS',
    },
  },
};
