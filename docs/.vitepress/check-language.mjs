import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const docsRoot = path.resolve(here, '..');

const publicRoots = [
  path.join(docsRoot, 'for-business'),
  path.join(docsRoot, 'for-integrators'),
];

const explicitFiles = [path.join(docsRoot, 'index.md')];

const allowedLatin = new Set([
  'COS',
  'CRM',
  'API',
  'HTTP',
  'HTTPS',
  'JSON',
  'XML',
  'CSV',
  'OAuth',
  'REST',
  'SQL',
  'ERP',
  'B2B',
  'SaaS',
  'Telegram',
  'Viber',
  'WhatsApp',
  'Google',
  'Microsoft',
  'Meta',
  'OpenAI',
  'SAP',
  'Sales',
  'Property',
  'Diagnostic',
  'Terra',
  'Nova',
]);

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

function visibleLine(line) {
  return line
    .replace(/<!--.*?-->/g, '')
    .replace(/`[^`]*`/g, '')
    .replace(/\]\([^)]*\)/g, ']')
    .replace(/https?:\/\/\S+/g, '')
    .replace(/<[^>]+>/g, '');
}

function scanFile(file) {
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

const files = [...new Set([...explicitFiles, ...publicRoots.flatMap(walkMarkdown)])];
const errors = files.flatMap(scanFile);

if (errors.length > 0) {
  console.error(`Перевірка мови не пройдена (${errors.length}):`);
  for (const error of errors) console.error(`- ${error}`);
  console.error('Нетехнічна українська документація не повинна містити звичайну англійську лексику.');
  process.exit(1);
}

console.log(`Перевірка мови пройдена: ${files.length} публічних та інтеграторських сторінок.`);
