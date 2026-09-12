import { buildSidebar } from './sidebar.mjs';

export default {
  title: 'COS Documentation',
  description: 'Company Operating System product, architecture, runtime and development documentation.',
  lang: 'uk-UA',
  base: '/docs/',
  outDir: '../public/docs',
  cleanUrls: false,
  lastUpdated: true,
  markdown: {
    lineNumbers: true,
  },
  head: [
    ['meta', { name: 'theme-color', content: '#111827' }],
  ],
  themeConfig: {
    siteTitle: 'COS Documentation',
    nav: [
      { text: 'Start', link: '/00-start/what-is-cos' },
      { text: 'Workflows', link: '/02-workflows/sales-lead-to-managed-case' },
      { text: 'Architecture', link: '/03-architecture/domain-map' },
      { text: 'Domains', link: '/04-domains/sales/overview' },
      { text: 'Reference', link: '/12-reference/glossary' },
    ],
    sidebar: buildSidebar(),
    outline: {
      level: [2, 3],
      label: 'На цій сторінці',
    },
    search: {
      provider: 'local',
      options: {
        async _render(src, env, md) {
          const html = await md.renderAsync(src, env);
          const path = env.relativePath || '';
          if (path.startsWith('architecture/') || path.startsWith('api/') || path.startsWith('diagnostic/')) {
            return '';
          }
          if (env.frontmatter?.search === false) return '';
          return html;
        },
      },
    },
    editLink: {
      pattern: 'https://github.com/Murakhovsky/TerraNova2026/edit/COS/docs/:path',
      text: 'Редагувати на GitHub',
    },
    lastUpdated: {
      text: 'Оновлено',
      formatOptions: {
        dateStyle: 'medium',
        timeStyle: 'short',
      },
    },
    docFooter: {
      prev: 'Попередня сторінка',
      next: 'Наступна сторінка',
    },
    socialLinks: [
      { icon: 'github', link: 'https://github.com/Murakhovsky/TerraNova2026/tree/COS' },
    ],
    footer: {
      message: 'Документація описує гілку COS. Executable source of truth: code + tests.',
      copyright: 'Terra Nova COS',
    },
  },
};
