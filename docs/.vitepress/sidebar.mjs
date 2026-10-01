import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { AUDIENCE_STRUCTURE, getLocale, localizedUrl, translated } from './locales.mjs';

const docsRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');

const developerSections = [
  ['00-start', 'Початок'],
  ['01-product', 'Продукт'],
  ['02-workflows', 'Бізнес-процеси'],
  ['03-architecture', 'Архітектура системи'],
  ['04-domains', 'Домени'],
  ['05-runtime', 'Середовище виконання'],
  ['06-ai-agents', 'ШІ та агенти'],
  ['07-api-integrations', 'API та інтеграції'],
  ['08-ui', 'Інтерфейси'],
  ['09-development', 'Розробка'],
  ['10-operations', 'Експлуатація'],
  ['11-decisions', 'Архітектурні рішення'],
  ['12-reference', 'Технічний довідник'],
];

const directoryLabels = new Map([
  ['sales', 'Продажі (Sales)'],
  ['property', 'Нерухомість (Property)'],
  ['diagnostic', 'Діагностика (Diagnostic)'],
]);

const preferredOrder = new Map([
  ['what-is-cos.md', -30],
  ['mental-model.md', -20],
  ['reading-paths.md', -15],
  ['repository-map.md', -10],
  ['vision-and-principles.md', -40],
  ['capabilities.md', -30],
  ['actors-and-authority.md', -20],
  ['system-boundaries.md', -10],
  ['business-process-modeling.md', -40],
  ['overview.md', -30],
  ['domain-model.md', -20],
  ['lifecycle-and-automation.md', -10],
  ['lifecycle-and-runtime.md', -10],
  ['lifecycle-and-evaluation.md', -10],
  ['README.md', -30],
  ['current-scope.md', -5],
  ['system-map.md', -40],
  ['domain-map.md', -30],
  ['kernel-overview.md', -20],
  ['extension-runtime.md', -10],
  ['execution-lifecycle.md', -20],
  ['agent-runtime.md', -40],
  ['context-and-tools.md', -30],
  ['memory-model.md', -20],
  ['agent-evaluation.md', -10],
  ['llm-boundary.md', 0],
  ['llm-governance.md', 10],
  ['integration-model.md', -40],
  ['api-and-webhooks.md', -30],
  ['messaging-channels.md', -20],
  ['external-reliability.md', -10],
  ['interface-surfaces.md', -40],
  ['workspace-model.md', -30],
  ['navigation-and-permissions.md', -20],
  ['documentation-site.md', -10],
  ['local-setup.md', -40],
  ['adding-a-domain.md', -30],
  ['adding-a-module.md', -29],
  ['adding-a-workflow.md', -28],
  ['adding-an-agent.md', -27],
  ['adding-an-integration.md', -26],
  ['testing.md', -20],
  ['deployment-and-health.md', -50],
  ['module-readiness.md', -40],
  ['data-and-migrations.md', -30],
  ['observability-and-incidents.md', -20],
  ['backup-and-recovery.md', -10],
  ['security-operations.md', 0],
  ['documentation-build.md', 10],
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

function audienceSidebar(localeId) {
  return AUDIENCE_STRUCTURE.map((audience) => ({
    text: translated(audience.labels, localeId),
    collapsed: false,
    items: audience.pages.map((page) => {
      const semantic = audience.directory + (page.slug === 'index' ? '' : '/' + page.slug);
      return {
        text: translated(page.labels, localeId),
        link: localizedUrl(localeId, semantic),
      };
    }),
  }));
}

export function buildSidebar(localeReference) {
  const locale = getLocale(localeReference);
  const audience = audienceSidebar(locale.id);

  if (locale.id !== 'uk') return audience;

  const technicalBase = developerSections.map(([directory, text]) => ({
    text,
    collapsed: true,
    items: itemsForDirectory(join(docsRoot, directory)),
  }));

  return [
    ...audience,
    {
      text: locale.ui.technicalCorpus,
      collapsed: true,
      items: technicalBase,
    },
  ];
}
