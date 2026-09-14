import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const docsRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');

const sections = [
  ['00-start', 'Start Here'],
  ['01-product', 'Product'],
  ['02-workflows', 'Business Workflows'],
  ['03-architecture', 'System Architecture'],
  ['04-domains', 'Domains'],
  ['05-runtime', 'Runtime'],
  ['06-ai-agents', 'AI / Agents'],
  ['07-api-integrations', 'API & Integrations'],
  ['08-ui', 'UI'],
  ['09-development', 'Development'],
  ['10-operations', 'Operations'],
  ['11-decisions', 'Architecture Decisions'],
  ['12-reference', 'Reference'],
];

const preferredOrder = new Map([
  ['what-is-cos.md', -30],
  ['mental-model.md', -20],
  ['repository-map.md', -10],
  ['overview.md', -30],
  ['README.md', -30],
  ['current-scope.md', -20],
  ['domain-map.md', -30],
  ['kernel-overview.md', -20],
  ['extension-runtime.md', -10],
  ['execution-lifecycle.md', -20],
  ['agent-runtime.md', -20],
  ['llm-governance.md', -10],
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
  return value
    .replace(/^\d+-/, '')
    .replace(/[-_]+/g, ' ')
    .replace(/\b\w/g, (letter) => letter.toUpperCase());
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
        return {
          text: humanize(entry.name),
          collapsed: true,
          items,
        };
      }
      return {
        text: titleFromMarkdown(entryPath),
        link: linkFor(entryPath),
      };
    })
    .filter(Boolean);
}

export function buildSidebar() {
  return sections.map(([directory, text], index) => ({
    text,
    collapsed: index > 2,
    items: itemsForDirectory(join(docsRoot, directory)),
  }));
}
