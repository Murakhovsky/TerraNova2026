import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(here, '..', '..');

export const PROCESS_REGISTRY_ROOT = path.join(repoRoot, 'resources', 'processes');

export function loadProcessDefinitions() {
  if (!fs.existsSync(PROCESS_REGISTRY_ROOT)) return [];

  return fs.readdirSync(PROCESS_REGISTRY_ROOT)
    .filter((name) => name.endsWith('.json'))
    .sort()
    .map((name) => JSON.parse(fs.readFileSync(path.join(PROCESS_REGISTRY_ROOT, name), 'utf8')));
}
