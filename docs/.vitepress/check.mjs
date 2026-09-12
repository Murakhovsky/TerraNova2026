import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(here, '..', '..');
const docsRoot = path.join(repoRoot, 'docs');
const errors = [];
let checkedLinks = 0;
let checkedFrontmatter = 0;

function relativeToRepo(file) {
  return path.relative(repoRoot, file).split(path.sep).join('/');
}

function walk(directory) {
  const files = [];
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    if (entry.name === '.vitepress' || entry.name === 'node_modules') continue;
    const absolute = path.join(directory, entry.name);
    if (entry.isDirectory()) files.push(...walk(absolute));
    else if (entry.isFile() && entry.name.endsWith('.md')) files.push(absolute);
  }
  return files;
}

function parseFrontmatter(file, content) {
  const relative = path.relative(docsRoot, file).split(path.sep).join('/');
  const canonical = /^\d{2}-[^/]+\//.test(relative) && path.basename(file).toLowerCase() !== 'readme.md';
  const normalized = content.replace(/\r\n/g, '\n');

  if (!normalized.startsWith('---\n')) {
    if (canonical) errors.push(`${relative}: canonical page is missing frontmatter`);
    return null;
  }

  const lines = normalized.split('\n');
  const end = lines.indexOf('---', 1);
  if (end === -1) {
    errors.push(`${relative}: frontmatter is not closed with ---`);
    return null;
  }

  const values = new Map();
  for (let index = 1; index < end; index += 1) {
    const line = lines[index];
    if (!line.trim() || line.trimStart().startsWith('#')) continue;
    if (/^\s/.test(line) || /^-\s/.test(line)) continue;

    const match = line.match(/^([A-Za-z0-9_-]+):\s*(.*)$/);
    if (!match) {
      errors.push(`${relative}:${index + 1}: malformed frontmatter entry`);
      continue;
    }

    const [, key, value] = match;
    if (values.has(key)) errors.push(`${relative}:${index + 1}: duplicate frontmatter key '${key}'`);
    values.set(key, value.trim());
  }

  checkedFrontmatter += 1;

  if (canonical) {
    for (const key of ['title', 'status', 'updated', 'kind']) {
      if (!values.get(key)) errors.push(`${relative}: required frontmatter key '${key}' is missing or empty`);
    }
  }

  const updated = values.get('updated');
  if (updated && !/^\d{4}-\d{2}-\d{2}$/.test(updated)) {
    errors.push(`${relative}: frontmatter 'updated' must use YYYY-MM-DD`);
  }

  return values;
}

function stripCodeFences(content) {
  return content
    .replace(/```[\s\S]*?```/g, '')
    .replace(/~~~[\s\S]*?~~~/g, '');
}

function normalizeLinkTarget(raw) {
  let target = raw.trim();
  if (target.startsWith('<') && target.includes('>')) {
    target = target.slice(1, target.indexOf('>'));
  } else {
    const titleSeparator = target.search(/\s+["']/);
    if (titleSeparator !== -1) target = target.slice(0, titleSeparator);
  }
  return target.trim();
}

function candidatesFor(targetPath) {
  const candidates = [targetPath];
  const extension = path.extname(targetPath).toLowerCase();

  if (extension === '.html') {
    candidates.push(targetPath.slice(0, -5) + '.md');
    candidates.push(path.join(targetPath.slice(0, -5), 'index.md'));
  } else if (!extension) {
    candidates.push(`${targetPath}.md`);
    candidates.push(path.join(targetPath, 'index.md'));
    candidates.push(path.join(targetPath, 'README.md'));
  }

  return [...new Set(candidates)];
}

function checkLinks(file, content) {
  const relative = path.relative(docsRoot, file).split(path.sep).join('/');
  const source = stripCodeFences(content);
  const regex = /(?<!!)\[[^\]]*\]\(([^)]+)\)/g;
  let match;

  while ((match = regex.exec(source)) !== null) {
    const raw = normalizeLinkTarget(match[1]);
    if (!raw || raw.startsWith('#')) continue;
    if (/^(?:https?:|mailto:|tel:|data:|javascript:|\/\/)/i.test(raw)) continue;

    let target = raw.split('#', 1)[0].split('?', 1)[0];
    if (!target) continue;

    try {
      target = decodeURIComponent(target);
    } catch {
      errors.push(`${relative}: invalid percent-encoding in link '${raw}'`);
      continue;
    }

    let absolute;
    if (target.startsWith('/docs/')) absolute = path.join(repoRoot, target.slice(1));
    else if (target.startsWith('/')) absolute = path.join(docsRoot, target.slice(1));
    else absolute = path.resolve(path.dirname(file), target);

    const insideRepo = absolute === repoRoot || absolute.startsWith(`${repoRoot}${path.sep}`);
    if (!insideRepo) {
      errors.push(`${relative}: link escapes repository '${raw}'`);
      continue;
    }

    checkedLinks += 1;
    const exists = candidatesFor(absolute).some((candidate) => fs.existsSync(candidate));
    if (!exists) errors.push(`${relative}: broken internal link '${raw}'`);
  }
}

function checkKernelVersion() {
  const runtimePath = path.join(repoRoot, 'app', 'Kernel', 'Module', 'KernelVersion.php');
  const docsPath = path.join(docsRoot, '03-architecture', 'kernel-overview.md');
  const runtime = fs.readFileSync(runtimePath, 'utf8');
  const docs = fs.readFileSync(docsPath, 'utf8');

  const runtimeMatch = runtime.match(/public\s+const\s+VERSION\s*=\s*['"]([^'"]+)['"]/);
  const docsMatch = docs.match(/Поточний executable Kernel contract:\s*\*\*`([^`]+)`\*\*/);

  if (!runtimeMatch) {
    errors.push(`${relativeToRepo(runtimePath)}: cannot read KernelVersion::VERSION`);
    return;
  }
  if (!docsMatch) {
    errors.push(`${relativeToRepo(docsPath)}: executable Kernel contract marker is missing`);
    return;
  }
  if (runtimeMatch[1] !== docsMatch[1]) {
    errors.push(`${relativeToRepo(docsPath)}: Kernel version drift: docs=${docsMatch[1]}, runtime=${runtimeMatch[1]}`);
  }
}

if (!fs.existsSync(docsRoot)) {
  console.error('Documentation root not found:', docsRoot);
  process.exit(1);
}

const markdownFiles = walk(docsRoot);
for (const file of markdownFiles) {
  const content = fs.readFileSync(file, 'utf8');
  parseFrontmatter(file, content);
  checkLinks(file, content);
}
checkKernelVersion();

if (errors.length > 0) {
  console.error(`Documentation checks failed (${errors.length}):`);
  for (const error of errors) console.error(`- ${error}`);
  process.exit(1);
}

console.log(`Documentation checks passed: ${markdownFiles.length} Markdown files, ${checkedFrontmatter} frontmatter blocks, ${checkedLinks} internal links.`);
