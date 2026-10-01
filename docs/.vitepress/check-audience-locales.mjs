import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { AUDIENCE_STRUCTURE, DEFAULT_LOCALE, LOCALES, localizedSourcePath } from './locales.mjs';

const errors = [];

function frontmatter(source) {
  const match = source.match(/^---\s*\n([\s\S]*?)\n---/);
  if (!match) return {};
  const values = {};
  for (const line of match[1].split('\n')) {
    const item = line.match(/^([A-Za-z0-9_-]+):\s*(.*)$/);
    if (!item) continue;
    values[item[1]] = item[2].trim().replace(/^['"]|['"]$/g, '');
  }
  return values;
}

const ids = new Set();
const viteKeys = new Set();
const prefixes = new Set();

for (const locale of LOCALES) {
  if (ids.has(locale.id)) errors.push('Duplicate locale id: ' + locale.id);
  ids.add(locale.id);

  if (viteKeys.has(locale.viteKey)) errors.push('Duplicate VitePress locale key: ' + locale.viteKey);
  viteKeys.add(locale.viteKey);

  if (prefixes.has(locale.prefix)) errors.push('Duplicate locale prefix: ' + locale.prefix);
  prefixes.add(locale.prefix);

  if (locale.id === DEFAULT_LOCALE && locale.prefix !== '') {
    errors.push('Default locale must keep the root URL for backward compatibility.');
  }
  if (locale.id !== DEFAULT_LOCALE && !locale.prefix) {
    errors.push('Non-default locale ' + locale.id + ' must have a URL prefix.');
  }

  for (const audience of AUDIENCE_STRUCTURE) {
    for (const page of audience.pages) {
      const semantic = audience.directory + '/' + page.slug;
      const file = localizedSourcePath(locale.id, semantic);
      if (!fs.existsSync(file)) {
        errors.push(locale.id + ': missing audience page ' + path.relative(process.cwd(), file));
        continue;
      }

      const source = fs.readFileSync(file, 'utf8');
      const meta = frontmatter(source);
      if (!meta.title) errors.push(locale.id + ': missing title in ' + path.relative(process.cwd(), file));
      if (meta.status !== 'active') errors.push(locale.id + ': audience page must be active: ' + path.relative(process.cwd(), file));
    }
  }
}

if (errors.length > 0) {
  console.error('Audience/locale checks failed (' + errors.length + '):');
  for (const error of errors) console.error('- ' + error);
  process.exit(1);
}

const pageCount = AUDIENCE_STRUCTURE.reduce((sum, audience) => sum + audience.pages.length, 0);
console.log(
  'Audience/locale checks passed: ' +
  LOCALES.length + ' locales × ' + pageCount + ' required audience pages. ' +
  'Add a language through locales.mjs plus the same semantic page set.'
);
