import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';
import { DOCUMENTATION_CONTRACTS } from './documentation-contracts.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const docsRoot = path.resolve(here, '..');
const templateRoot = path.join(here, 'templates');

const aliases = {
  concept: 'concept-v1',
  workflow: 'workflow-v2',
  architecture: 'architecture-v1',
  domain: 'domain-v1',
  'how-to': 'how-to-v1',
  reference: 'reference-v1',
};

const templateNames = {
  'concept-v1': 'concept.md',
  'workflow-v2': 'workflow.md',
  'architecture-v1': 'architecture.md',
  'domain-v1': 'domain.md',
  'how-to-v1': 'how-to.md',
  'reference-v1': 'reference.md',
};

const [requestedType, requestedPath, ...titleParts] = process.argv.slice(2);
const title = titleParts.join(' ').trim();

if (!requestedType || !requestedPath || !title) {
  console.error('Usage: npm run docs:new -- <concept|workflow|architecture|domain|how-to|reference> <relative-path.md> <Title>');
  process.exit(1);
}

const contractName = aliases[requestedType] ?? requestedType;
if (!DOCUMENTATION_CONTRACTS[contractName] || !templateNames[contractName]) {
  console.error(`Unknown documentation page type: ${requestedType}`);
  process.exit(1);
}

const normalizedPath = requestedPath.endsWith('.md') ? requestedPath : `${requestedPath}.md`;
const target = path.resolve(docsRoot, normalizedPath);
if (!target.startsWith(`${docsRoot}${path.sep}`) || target.includes(`${path.sep}.vitepress${path.sep}`)) {
  console.error('Target must be a Markdown page inside docs/ and outside docs/.vitepress/.');
  process.exit(1);
}
if (fs.existsSync(target)) {
  console.error(`Documentation page already exists: ${path.relative(docsRoot, target)}`);
  process.exit(1);
}

const template = fs.readFileSync(path.join(templateRoot, templateNames[contractName]), 'utf8');
const date = new Date().toISOString().slice(0, 10);
const content = template
  .replaceAll('{{title}}', title)
  .replaceAll('{{date}}', date);

fs.mkdirSync(path.dirname(target), { recursive: true });
fs.writeFileSync(target, content);
console.log(`Created ${path.relative(docsRoot, target)} with ${contractName}.`);

if (contractName === 'workflow-v2') {
  console.log('Workflow V2 also requires a matching Process Registry definition in resources/processes/*.json.');
  console.log('Use schema v5 when a step crosses Domain ownership and map that step through a verified requires contract.');
  console.log('Run npm run docs:check after adding the registry definition; the check will reject an unregistered workflow.');
}
