import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const docsRoot = path.resolve(here, '..');
const i18nRoot = path.join(docsRoot, 'i18n');
const pageRoot = path.join(docsRoot, 'content', 'pages');

const errors = [];
const fail = (message) => errors.push(message);
const readJson = (file) => JSON.parse(fs.readFileSync(file, 'utf8'));

const registryPath = path.join(i18nRoot, 'locales.json');
if (!fs.existsSync(registryPath)) {
  console.error('Localization registry is missing: docs/i18n/locales.json');
  process.exit(1);
}

const registry = readJson(registryPath);
if (registry.schema_version !== 1) fail(`Unsupported locale registry schema: ${registry.schema_version}`);
if (registry.default_locale !== 'en') fail(`Default documentation locale must be en, got ${registry.default_locale}`);
if (registry.query_parameter !== 'lang') fail(`Locale query parameter must be lang, got ${registry.query_parameter}`);
if (!registry.storage_key) fail('Locale registry must define storage_key.');

const localeCodes = Object.keys(registry.locales ?? {});
if (!localeCodes.includes(registry.default_locale)) fail('Default locale is not declared in locales.');

const bundles = new Map();
for (const locale of localeCodes) {
  const file = path.join(i18nRoot, `${locale}.json`);
  if (!fs.existsSync(file)) {
    fail(`Missing locale bundle: docs/i18n/${locale}.json`);
    continue;
  }
  const bundle = readJson(file);
  if (bundle?._meta?.locale !== locale) fail(`Bundle ${locale}.json has mismatched _meta.locale.`);
  bundles.set(locale, bundle);

  const forbiddenTree = path.join(docsRoot, locale);
  if (fs.existsSync(forbiddenTree)) {
    fail(`Locale-specific documentation tree is forbidden: docs/${locale}/. Use one canonical route tree plus docs/i18n/${locale}.json.`);
  }
}

function getPath(object, key) {
  return key.split('.').reduce((value, segment) => {
    if (value && Object.prototype.hasOwnProperty.call(value, segment)) return value[segment];
    return undefined;
  }, object);
}

function collectKeys(page) {
  const keys = new Set();
  if (page.title_key) keys.add(page.title_key);
  for (const block of page.blocks ?? []) {
    if (block.key) keys.add(block.key);
    for (const item of block.items ?? []) {
      if (typeof item === 'string') keys.add(item);
      else if (item?.key) keys.add(item.key);
    }
  }
  return keys;
}

const pageFiles = fs.existsSync(pageRoot)
  ? fs.readdirSync(pageRoot).filter((name) => name.endsWith('.json')).sort()
  : [];

if (pageFiles.length === 0) fail('No canonical localized page structures found in docs/content/pages/.');

const ids = new Set();
const routes = new Set();
const requiredKeys = new Set();
for (const name of pageFiles) {
  const page = readJson(path.join(pageRoot, name));
  if (page.schema_version !== 1) fail(`${name}: unsupported page schema ${page.schema_version}`);
  if (!page.id) fail(`${name}: missing id`);
  if (!page.route) fail(`${name}: missing route`);
  if (ids.has(page.id)) fail(`${name}: duplicate page id ${page.id}`);
  if (routes.has(page.route)) fail(`${name}: duplicate route ${page.route}`);
  ids.add(page.id);
  routes.add(page.route);
  for (const key of collectKeys(page)) requiredKeys.add(key);
}

const defaultBundle = bundles.get(registry.default_locale) ?? {};
for (const key of requiredKeys) {
  if (getPath(defaultBundle, key) === undefined) fail(`Default English bundle is missing required key: ${key}`);
}

for (const locale of localeCodes) {
  const bundle = bundles.get(locale) ?? {};
  const translated = [...requiredKeys].filter((key) => getPath(bundle, key) !== undefined).length;
  const total = requiredKeys.size;
  const percent = total === 0 ? 100 : Math.round((translated / total) * 1000) / 10;
  console.log(`Localization coverage ${locale}: ${translated}/${total} (${percent}%)`);
}

const config = fs.readFileSync(path.join(here, 'config.mjs'), 'utf8');
const sidebar = fs.readFileSync(path.join(here, 'sidebar.mjs'), 'utf8');
const runtime = fs.readFileSync(path.join(here, 'theme', 'locale-runtime.mjs'), 'utf8');

if (config.includes("link: '/en/'") || config.includes("'/en/for-")) fail('VitePress config still contains path-based /en locale routing.');
if (sidebar.includes('buildEnglishSidebar')) fail('Separate English sidebar builder is forbidden; use buildSidebar(locale).');
if (!config.includes("buildSidebar('en')")) fail('VitePress must build the canonical navigation from the English default locale.');
if (!config.includes('buildI18nConfig()')) fail('VitePress config must expose the unified localization metadata.');
if (!runtime.includes('queryParameter') || !runtime.includes('localizedHref')) fail('Locale runtime must support query-param routing and localized internal links.');

const wrappers = new Map([
  ['business', path.join(docsRoot, 'for-business', 'index.md')],
  ['integrators', path.join(docsRoot, 'for-integrators', 'index.md')],
  ['developers', path.join(docsRoot, 'for-developers', 'index.md')],
]);
for (const [id, file] of wrappers) {
  const source = fs.readFileSync(file, 'utf8');
  if (!source.includes(`<LocalizedPage page-id="${id}" />`)) fail(`${path.relative(docsRoot, file)} must render canonical localized page ${id}.`);
}
if (!fs.readFileSync(path.join(docsRoot, 'index.md'), 'utf8').includes('<LocalizedHome />')) {
  fail('docs/index.md must render the unified LocalizedHome component.');
}

if (errors.length > 0) {
  console.error(`Localization architecture check failed (${errors.length}):`);
  for (const error of errors) console.error(`- ${error}`);
  process.exit(1);
}

console.log(`Localization architecture passed: ${localeCodes.length} locales, ${pageFiles.length} canonical structured pages, default=en, routing=?lang=<locale>.`);
