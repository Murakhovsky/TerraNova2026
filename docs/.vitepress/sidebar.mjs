import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { translate } from './i18n.mjs';

const docsRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');

const developerSections = [
  ['00-start', 'nav.sections.start'],
  ['01-product', 'nav.sections.product'],
  ['02-workflows', 'nav.sections.workflows'],
  ['03-architecture', 'nav.sections.architecture'],
  ['04-domains', 'nav.sections.domains'],
  ['05-runtime', 'nav.sections.runtime'],
  ['06-ai-agents', 'nav.sections.agents'],
  ['07-api-integrations', 'nav.sections.api'],
  ['08-ui', 'nav.sections.ui'],
  ['09-development', 'nav.sections.development'],
  ['10-operations', 'nav.sections.operations'],
  ['11-decisions', 'nav.sections.decisions'],
  ['12-reference', 'nav.sections.reference'],
];

const directoryLabels = new Map([
  ['sales', 'Sales'],
  ['property', 'Property'],
  ['diagnostic', 'Diagnostic'],
  ['support', 'Support'],
]);

const preferredOrder = new Map([
  ['what-is-cos.md', -30], ['mental-model.md', -20], ['reading-paths.md', -15], ['repository-map.md', -10],
  ['vision-and-principles.md', -40], ['capabilities.md', -30], ['actors-and-authority.md', -20], ['system-boundaries.md', -10],
  ['business-process-modeling.md', -40], ['overview.md', -30], ['domain-model.md', -20], ['lifecycle-and-automation.md', -10],
  ['lifecycle-and-runtime.md', -10], ['lifecycle-and-evaluation.md', -10], ['README.md', -30], ['current-scope.md', -5],
  ['system-map.md', -40], ['domain-map.md', -30], ['kernel-overview.md', -20], ['extension-runtime.md', -10],
  ['execution-lifecycle.md', -20], ['agent-runtime.md', -40], ['context-and-tools.md', -30], ['memory-model.md', -20],
  ['agent-evaluation.md', -10], ['llm-boundary.md', 0], ['llm-governance.md', 10], ['integration-model.md', -40],
  ['api-and-webhooks.md', -30], ['messaging-channels.md', -20], ['external-reliability.md', -10], ['interface-surfaces.md', -40],
  ['workspace-model.md', -30], ['navigation-and-permissions.md', -20], ['documentation-site.md', -10], ['local-setup.md', -40],
  ['adding-a-domain.md', -30], ['adding-a-module.md', -29], ['adding-a-workflow.md', -28], ['adding-an-agent.md', -27],
  ['adding-an-integration.md', -26], ['testing.md', -20], ['deployment-and-health.md', -50], ['module-readiness.md', -40],
  ['data-and-migrations.md', -30], ['observability-and-incidents.md', -20], ['backup-and-recovery.md', -10],
  ['security-operations.md', 0], ['documentation-build.md', 10],
]);

function titleFromMarkdown(path) {
  const source = readFileSync(path, 'utf8');
  const frontmatter = source.match(/^---\s*\n([\s\S]*?)\n---/);
  if (frontmatter) {
    const title = frontmatter[1].match(/^title:\s*(.+)$/m)?.[1]?.trim();
    if (title) return title.replace(/^['"]|['"]$/g, '');
  }
  return source.match(/^#\s+(.+)$/m)?.[1]?.trim() || humanize(path.split(sep).pop().replace(/\.md$/, ''));
}

function humanize(value) {
  if (directoryLabels.has(value)) return directoryLabels.get(value);
  return value.replace(/^\d+-/, '').replace(/[-_]+/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function sortEntries(a, b) {
  if (a.isDirectory() !== b.isDirectory()) return a.isDirectory() ? 1 : -1;
  const aRank = preferredOrder.get(a.name) ?? 0;
  const bRank = preferredOrder.get(b.name) ?? 0;
  if (aRank !== bRank) return aRank - bRank;
  return a.name.localeCompare(b.name, 'en');
}

function linkFor(path) {
  return '/' + relative(docsRoot, path).split(sep).join('/').replace(/\.md$/, '');
}

function itemsForDirectory(path) {
  return readdirSync(path, { withFileTypes: true })
    .filter((entry) => !entry.name.startsWith('.') && (entry.isDirectory() || entry.name.endsWith('.md')))
    .sort(sortEntries)
    .map((entry) => {
      const entryPath = join(path, entry.name);
      if (entry.isDirectory()) {
        const items = itemsForDirectory(entryPath);
        if (items.length === 0) return null;
        return { text: humanize(entry.name), collapsed: true, items };
      }
      return { text: titleFromMarkdown(entryPath), link: linkFor(entryPath) };
    })
    .filter(Boolean);
}

export function buildSidebar(locale = 'en') {
  const t = (key, fallback) => translate(locale, key, fallback);
  const technicalBase = developerSections.map(([directory, textKey]) => ({
    text: t(textKey, humanize(directory)),
    collapsed: true,
    items: itemsForDirectory(join(docsRoot, directory)),
  }));

  return [
    {
      text: t('nav.businessGroup', 'For business and users'),
      collapsed: false,
      items: [
        { text: t('nav.whatIsCos', 'What COS is'), link: '/for-business/' },
        { text: t('nav.capabilities', 'Capabilities'), link: '/for-business/capabilities' },
        { text: t('nav.useCases', 'Use cases'), link: '/for-business/use-cases' },
        { text: t('nav.implementationGuide', 'How implementation works'), link: '/for-business/implementation' },
        { text: t('nav.faq', 'Frequently asked questions'), link: '/for-business/faq' },
      ],
    },
    {
      text: t('nav.integratorsGroup', 'For implementation professionals'),
      collapsed: false,
      items: [
        { text: t('nav.implementationRoute', 'Implementation route'), link: '/for-integrators/' },
        { text: t('nav.discovery', 'Process discovery'), link: '/for-integrators/discovery' },
        { text: t('nav.dataIntegrations', 'Data and integrations'), link: '/for-integrators/data-and-integrations' },
        { text: t('nav.automationAi', 'Automation and AI'), link: '/for-integrators/automation-and-ai' },
        { text: t('nav.readiness', 'Readiness check'), link: '/for-integrators/readiness' },
      ],
    },
    {
      text: t('nav.developersGroup', 'For developers'),
      collapsed: false,
      items: [
        { text: t('nav.developerEntry', 'Developer entry point'), link: '/for-developers/' },
        ...technicalBase,
      ],
    },
  ];
}
