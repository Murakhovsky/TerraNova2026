import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const docsRoot = path.resolve(here, '..');
const repoRoot = path.resolve(docsRoot, '..');

const strictRoots = [
  path.join(docsRoot, 'for-business'),
  path.join(docsRoot, 'for-integrators'),
];

const structuralRoots = [
  ...fs.readdirSync(docsRoot, { withFileTypes: true })
    .filter((entry) => entry.isDirectory() && /^\d{2}-/.test(entry.name))
    .map((entry) => path.join(docsRoot, entry.name)),
  path.join(docsRoot, 'for-developers'),
];

const explicitStrictFiles = [path.join(docsRoot, 'index.md')];

const allowedLatin = new Set([
  'COS', 'CRM', 'API', 'HTTP', 'HTTPS', 'JSON', 'XML', 'CSV', 'OAuth', 'REST', 'SQL', 'ERP', 'B2B', 'SaaS',
  'Telegram', 'Viber', 'WhatsApp', 'Google', 'Microsoft', 'Meta', 'OpenAI', 'SAP',
  'Sales', 'Property', 'Diagnostic', 'Terra', 'Nova',
]);

const technicalHeadingWords = new Set([
  ...allowedLatin,
  'Kernel', 'Runtime', 'Process', 'Registry', 'Capability', 'Capabilities', 'Event', 'Events', 'Outbox',
  'Rule', 'Rules', 'Agent', 'Agents', 'Action', 'Actions', 'Policy', 'Policies', 'Approval', 'Queue', 'Audit',
  'LLM', 'AI', 'Module', 'Modules', 'Extension', 'Extensions', 'Point', 'Points', 'Command', 'DTO', 'Application',
  'Use', 'Case', 'Cases', 'Web', 'UI', 'Workspace', 'Portal', 'Public', 'Domain', 'Domains', 'Architecture', 'Explorer',
  'Visualization', 'Reference', 'Graph', 'Contract', 'Contracts', 'Integration', 'Integrations', 'Interface', 'Interfaces',
  'Code', 'Map', 'AS', 'IS', 'TARGET', 'PHP', 'MySQL', 'AWS', 'CI', 'CLI', 'Vite', 'VitePress', 'Mermaid', 'BPMN',
  'Cytoscape', 'JavaScript', 'CSS', 'HTML', 'ADR', 'MCP', 'SEO', 'URL', 'URLs', 'CRUD', 'DTOs', 'FQCN',
  'Identity', 'Content', 'Spatial', 'Bootstrap', 'Asset', 'Inventory', 'Item', 'Listing', 'Publication',
  'ModuleExtensionRegistry', 'EventBus', 'ActionPolicy', 'AgentDefinition', 'AUTO', 'DENIED', 'n8n',
]);

const exactTechnicalHeadings = new Set([
  'Property Asset',
  'Inventory Item',
  'Listing',
  'Publication',
  'Identity',
  'Content',
  'Spatial',
  'Bootstrap',
  'ModuleExtensionRegistry',
  'EventBus',
  'AUTO',
  'DENIED',
  'ActionPolicy',
  'AgentDefinition',
  'n8n',
]);

function loadProcessTitles() {
  const processRoot = path.join(repoRoot, 'resources', 'processes');
  if (!fs.existsSync(processRoot)) return new Set();
  const titles = new Set();
  for (const entry of fs.readdirSync(processRoot, { withFileTypes: true })) {
    if (!entry.isFile() || !entry.name.endsWith('.json')) continue;
    try {
      const data = JSON.parse(fs.readFileSync(path.join(processRoot, entry.name), 'utf8'));
      if (typeof data.title === 'string' && data.title.trim()) titles.add(data.title.trim());
    } catch {
      // Process contract checks own malformed JSON. Language validation should not duplicate that responsibility.
    }
  }
  return titles;
}

const processTitles = loadProcessTitles();

function walkMarkdown(directory) {
  if (!fs.existsSync(directory)) return [];
  const files = [];
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    const absolute = path.join(directory, entry.name);
    if (entry.isDirectory()) files.push(...walkMarkdown(absolute));
    else if (entry.isFile() && entry.name.endsWith('.md')) files.push(absolute);
  }
  return files;
}

function relative(file) {
  return path.relative(docsRoot, file).split(path.sep).join('/');
}

function hasUkrainian(value) {
  return /[А-Яа-яІіЇїЄєҐґ]/u.test(value ?? '');
}

function visibleLine(line) {
  return line
    .replace(/<!--.*?-->/g, '')
    .replace(/`[^`]*`/g, '')
    .replace(/\[([^\]]+)\]\([^)]*\)/g, '$1')
    .replace(/https?:\/\/\S+/g, '')
    .replace(/<[^>]+>/g, '');
}

function parseFrontmatter(source) {
  const match = source.match(/^---\s*\n([\s\S]*?)\n---/);
  if (!match) return {};
  const result = {};
  for (const key of ['title', 'description', 'generated']) {
    const value = match[1].match(new RegExp(`^${key}:\\s*(.+)$`, 'm'))?.[1]?.trim();
    if (value) result[key] = value.replace(/^['"]|['"]$/g, '');
  }
  return result;
}

function isTechnicalHeading(text, file) {
  const trimmed = text.trim();
  if (processTitles.has(trimmed)) return true;
  if (exactTechnicalHeadings.has(trimmed)) return true;
  if (relative(file) === '12-reference/glossary.md') return true;

  const visible = visibleLine(text)
    .replace(/V\d+(?:\.\d+)*/gi, ' ')
    .replace(/\b\d+(?:\.\d+)*\b/g, ' ')
    .replace(/[→←↔–—/:()\[\],.&+*=|]/g, ' ');
  const tokens = visible.match(/\b[A-Za-z][A-Za-z0-9.+-]*\b/g) ?? [];
  if (tokens.length === 0) return true;
  return tokens.every((token) => technicalHeadingWords.has(token));
}

function scanStrictFile(file) {
  const lines = fs.readFileSync(file, 'utf8').replace(/\r\n/g, '\n').split('\n');
  const errors = [];
  let inFrontmatter = lines[0]?.trim() === '---';
  let inFence = false;
  let fenceMarker = null;

  for (let index = 0; index < lines.length; index += 1) {
    const raw = lines[index];
    const trimmed = raw.trim();

    if (inFrontmatter) {
      if (index > 0 && trimmed === '---') inFrontmatter = false;
      continue;
    }

    const fence = trimmed.match(/^(```|~~~)/)?.[1];
    if (fence) {
      if (!inFence) {
        inFence = true;
        fenceMarker = fence;
      } else if (fence === fenceMarker) {
        inFence = false;
        fenceMarker = null;
      }
      continue;
    }
    if (inFence) continue;

    const text = visibleLine(raw);
    const tokens = text.match(/\b[A-Za-z][A-Za-z0-9.+-]*\b/g) ?? [];
    for (const token of tokens) {
      if (allowedLatin.has(token)) continue;
      errors.push(`${relative(file)}:${index + 1}: неперекладене слово '${token}'`);
    }
  }

  return errors;
}

function scanStructuralFile(file) {
  const source = fs.readFileSync(file, 'utf8').replace(/\r\n/g, '\n');
  const lines = source.split('\n');
  const frontmatter = parseFrontmatter(source);
  const errors = [];

  if (!frontmatter.description || !hasUkrainian(frontmatter.description)) {
    errors.push(`${relative(file)}: frontmatter description має містити український опис`);
  }

  // Generated reference headings contain executable names and registry-owned titles.
  // Their presentation shell is localized by the generators; machine-derived headings are authority data.
  if (frontmatter.generated === 'true') return errors;

  let inFrontmatter = lines[0]?.trim() === '---';
  let inFence = false;
  let fenceMarker = null;
  let headingCount = 0;
  let localizedHeadingCount = 0;

  for (let index = 0; index < lines.length; index += 1) {
    const raw = lines[index];
    const trimmed = raw.trim();

    if (inFrontmatter) {
      if (index > 0 && trimmed === '---') inFrontmatter = false;
      continue;
    }

    const fence = trimmed.match(/^(```|~~~)/)?.[1];
    if (fence) {
      if (!inFence) {
        inFence = true;
        fenceMarker = fence;
      } else if (fence === fenceMarker) {
        inFence = false;
        fenceMarker = null;
      }
      continue;
    }
    if (inFence) continue;

    const heading = raw.match(/^(#{1,3})\s+(.+?)\s*$/);
    if (!heading) continue;
    headingCount += 1;
    const text = visibleLine(heading[2]);
    if (hasUkrainian(text)) {
      localizedHeadingCount += 1;
      continue;
    }
    if (isTechnicalHeading(text, file)) continue;
    errors.push(`${relative(file)}:${index + 1}: англомовний структурний заголовок '${heading[2]}'`);
  }

  if (headingCount > 0 && localizedHeadingCount === 0 && !hasUkrainian(frontmatter.title ?? '')) {
    errors.push(`${relative(file)}: немає жодного україномовного структурного заголовка`);
  }

  return errors;
}

const strictFiles = [...new Set([...explicitStrictFiles, ...strictRoots.flatMap(walkMarkdown)])];
const structuralFiles = [...new Set(structuralRoots.flatMap(walkMarkdown))];
const errors = [
  ...strictFiles.flatMap(scanStrictFile),
  ...structuralFiles.flatMap(scanStructuralFile),
];

if (errors.length > 0) {
  console.error(`Перевірка мови не пройдена (${errors.length}):`);
  for (const error of errors) console.error(`- ${error}`);
  console.error('Публічна українська документація не повинна містити звичайну англійську лексику; технічний корпус має мати українську metadata/heading оболонку.');
  process.exit(1);
}

console.log(`Перевірка мови пройдена: ${strictFiles.length} strict pages + ${structuralFiles.length} canonical technical pages.`);
