import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';
import { DOCUMENTATION_CONTRACTS, DOCUMENTATION_CONTRACT_NAMES } from './documentation-contracts.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const docsRoot = path.resolve(here, '..');
const errors = [];
const seenContracts = new Set();
let checkedPages = 0;

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

function relative(file) {
  return path.relative(docsRoot, file).split(path.sep).join('/');
}

function parseFrontmatter(content) {
  const normalized = content.replace(/\r\n/g, '\n');
  if (!normalized.startsWith('---\n')) return null;
  const lines = normalized.split('\n');
  const end = lines.indexOf('---', 1);
  if (end === -1) return null;

  const values = new Map();
  for (let index = 1; index < end; index += 1) {
    const match = lines[index].match(/^([A-Za-z0-9_-]+):\s*(.*)$/);
    if (match) values.set(match[1], match[2].trim().replace(/^['"]|['"]$/g, ''));
  }
  return values;
}

function stripCodeFences(content) {
  return content.replace(/```[\s\S]*?```/g, '').replace(/~~~[\s\S]*?~~~/g, '');
}

function escapeRegExp(value) {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

for (const file of walk(docsRoot)) {
  const content = fs.readFileSync(file, 'utf8');
  const frontmatter = parseFrontmatter(content);
  const contractName = frontmatter?.get('contract');
  if (!contractName) continue;

  checkedPages += 1;
  seenContracts.add(contractName);
  const contract = DOCUMENTATION_CONTRACTS[contractName];
  if (!contract) {
    errors.push(`${relative(file)}: unknown documentation contract '${contractName}'`);
    continue;
  }

  const kind = frontmatter.get('kind');
  if (kind !== contract.kind) {
    errors.push(`${relative(file)}: contract '${contractName}' requires kind '${contract.kind}', got '${kind ?? 'missing'}'`);
  }

  const source = stripCodeFences(content);
  const h1Count = (source.match(/^#\s+.+$/gm) ?? []).length;
  const h2Count = (source.match(/^##\s+.+$/gm) ?? []).length;
  if (h1Count !== 1) errors.push(`${relative(file)}: contract '${contractName}' requires exactly one H1, got ${h1Count}`);
  if (h2Count < (contract.minH2 ?? 0)) {
    errors.push(`${relative(file)}: contract '${contractName}' requires at least ${contract.minH2} H2 sections, got ${h2Count}`);
  }

  for (const section of contract.requiredSections ?? []) {
    const pattern = new RegExp(`^##\\s+${escapeRegExp(section)}\\s*$`, 'mi');
    if (!pattern.test(source)) errors.push(`${relative(file)}: contract '${contractName}' is missing '## ${section}'`);
  }

  for (const rawPattern of contract.requiredPatterns ?? []) {
    if (!(new RegExp(rawPattern, 'mi')).test(source)) {
      errors.push(`${relative(file)}: contract '${contractName}' is missing required structural pattern ${rawPattern}`);
    }
  }
}

for (const contractName of DOCUMENTATION_CONTRACT_NAMES) {
  if (!seenContracts.has(contractName)) errors.push(`documentation contract '${contractName}' has no canonical page coverage`);
}

if (errors.length > 0) {
  console.error(`Documentation contract checks failed (${errors.length}):`);
  for (const error of errors) console.error(`- ${error}`);
  process.exit(1);
}

console.log(`Documentation contract checks passed: ${checkedPages} contracted pages across ${seenContracts.size} contract types.`);
