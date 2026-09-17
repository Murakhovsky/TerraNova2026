import { readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const i18nRoot = resolve(here, '..', 'i18n');

export const localeRegistry = JSON.parse(readFileSync(join(i18nRoot, 'locales.json'), 'utf8'));
export const defaultLocale = localeRegistry.default_locale;

const bundles = new Map();
for (const locale of Object.keys(localeRegistry.locales)) {
  bundles.set(locale, JSON.parse(readFileSync(join(i18nRoot, `${locale}.json`), 'utf8')));
}

function getPath(object, key) {
  return key.split('.').reduce((value, segment) => {
    if (value && Object.prototype.hasOwnProperty.call(value, segment)) return value[segment];
    return undefined;
  }, object);
}

export function translate(locale, key, fallback = null) {
  const requested = getPath(bundles.get(locale) ?? {}, key);
  if (requested !== undefined && requested !== null) return requested;
  const canonical = getPath(bundles.get(defaultLocale) ?? {}, key);
  if (canonical !== undefined && canonical !== null) return canonical;
  return fallback ?? key;
}

export function buildLocaleMetadata() {
  return {
    schemaVersion: localeRegistry.schema_version,
    defaultLocale,
    queryParameter: localeRegistry.query_parameter,
    storageKey: localeRegistry.storage_key,
    locales: Object.entries(localeRegistry.locales).map(([code, meta]) => ({ code, ...meta })),
  };
}
