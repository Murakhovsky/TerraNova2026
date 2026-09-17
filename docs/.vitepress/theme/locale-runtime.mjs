import { computed, ref } from 'vue';
import { withBase } from 'vitepress';

const modules = import.meta.glob('../../i18n/*.json', { eager: true, import: 'default' });
const registryEntry = Object.entries(modules).find(([path]) => path.endsWith('/locales.json'));

if (!registryEntry) {
  throw new Error('COS documentation locale registry is missing.');
}

export const localeRegistry = registryEntry[1];
export const defaultLocale = localeRegistry.default_locale;
export const queryParameter = localeRegistry.query_parameter;
export const storageKey = localeRegistry.storage_key;

const bundles = Object.fromEntries(
  Object.entries(modules)
    .filter(([path]) => !path.endsWith('/locales.json'))
    .map(([path, bundle]) => [path.split('/').pop().replace(/\.json$/, ''), bundle]),
);

export const currentLocale = ref(defaultLocale);
export const localeInitialized = ref(false);
export const availableLocales = Object.entries(localeRegistry.locales).map(([code, meta]) => ({ code, ...meta }));

function supported(locale) {
  return typeof locale === 'string' && Object.prototype.hasOwnProperty.call(localeRegistry.locales, locale);
}

function getPath(object, key) {
  return key.split('.').reduce((value, segment) => {
    if (value && Object.prototype.hasOwnProperty.call(value, segment)) return value[segment];
    return undefined;
  }, object);
}

function browserLocale() {
  if (typeof window === 'undefined') return defaultLocale;
  const url = new URL(window.location.href);
  const requested = url.searchParams.get(queryParameter);
  if (supported(requested)) return requested;

  try {
    const stored = window.localStorage.getItem(storageKey);
    if (supported(stored)) return stored;
  } catch {
    // Storage is optional. URL and default locale remain authoritative.
  }

  return defaultLocale;
}

function localeUrl(locale) {
  if (typeof window === 'undefined') return '';
  const url = new URL(window.location.href);
  if (locale === defaultLocale) url.searchParams.delete(queryParameter);
  else url.searchParams.set(queryParameter, locale);
  return `${url.pathname}${url.search}${url.hash}`;
}

function ensureHeadLink(rel, attrs) {
  if (typeof document === 'undefined') return;
  const selector = attrs.hreflang
    ? `link[data-cos-i18n="true"][rel="${rel}"][hreflang="${attrs.hreflang}"]`
    : `link[data-cos-i18n="true"][rel="${rel}"]:not([hreflang])`;
  let node = document.head.querySelector(selector);
  if (!node) {
    node = document.createElement('link');
    node.dataset.cosI18n = 'true';
    node.rel = rel;
    document.head.appendChild(node);
  }
  for (const [key, value] of Object.entries(attrs)) node.setAttribute(key, value);
}

export function applyLocaleMetadata(locale = currentLocale.value) {
  if (typeof window === 'undefined' || typeof document === 'undefined') return;
  const meta = localeRegistry.locales[locale] ?? localeRegistry.locales[defaultLocale];
  document.documentElement.lang = meta.html_lang;

  const canonical = new URL(window.location.href);
  canonical.searchParams.delete(queryParameter);
  canonical.hash = '';
  ensureHeadLink('canonical', { href: canonical.toString() });

  for (const { code, html_lang: htmlLang } of availableLocales) {
    const alternate = new URL(canonical.toString());
    if (code !== defaultLocale) alternate.searchParams.set(queryParameter, code);
    ensureHeadLink('alternate', { hreflang: htmlLang, href: alternate.toString() });
  }
  ensureHeadLink('alternate', { hreflang: 'x-default', href: canonical.toString() });
}

export function initializeLocale() {
  if (typeof window === 'undefined') return defaultLocale;
  const locale = browserLocale();
  currentLocale.value = locale;
  localeInitialized.value = true;
  applyLocaleMetadata(locale);
  return locale;
}

export function setLocale(locale, { updateUrl = true } = {}) {
  const next = supported(locale) ? locale : defaultLocale;
  currentLocale.value = next;
  localeInitialized.value = true;

  if (typeof window !== 'undefined') {
    try {
      window.localStorage.setItem(storageKey, next);
    } catch {
      // Storage is a convenience, not a dependency.
    }
    if (updateUrl) window.history.replaceState(window.history.state, '', localeUrl(next));
    applyLocaleMetadata(next);
    window.dispatchEvent(new CustomEvent('cos:locale-changed', { detail: { locale: next } }));
  }

  return next;
}

export function syncLocaleUrl() {
  if (typeof window === 'undefined') return;
  const desired = localeUrl(currentLocale.value);
  const actual = `${window.location.pathname}${window.location.search}${window.location.hash}`;
  if (desired && desired !== actual) window.history.replaceState(window.history.state, '', desired);
  applyLocaleMetadata(currentLocale.value);
}

export function hasTranslation(key, locale = currentLocale.value) {
  return getPath(bundles[locale] ?? {}, key) !== undefined;
}

export function translate(key, fallback = null, locale = currentLocale.value) {
  const requested = getPath(bundles[locale] ?? {}, key);
  if (requested !== undefined && requested !== null) return requested;
  const canonical = getPath(bundles[defaultLocale] ?? {}, key);
  if (canonical !== undefined && canonical !== null) return canonical;
  return fallback ?? key;
}

export function localizedHref(href, locale = currentLocale.value) {
  if (!href || /^(?:[a-z]+:|#)/i.test(href)) return href;
  const based = href.startsWith('/') ? withBase(href) : href;
  if (locale === defaultLocale || typeof window === 'undefined') return based;
  const url = new URL(based, window.location.origin);
  url.searchParams.set(queryParameter, locale);
  return `${url.pathname}${url.search}${url.hash}`;
}

export function useLocale() {
  return {
    locale: computed(() => currentLocale.value),
    initialized: computed(() => localeInitialized.value),
    locales: availableLocales,
    defaultLocale,
    t: translate,
    hasTranslation,
    setLocale,
    initializeLocale,
    syncLocaleUrl,
    localizedHref,
  };
}
