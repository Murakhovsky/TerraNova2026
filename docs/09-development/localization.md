---
title: Documentation localization
description: How COS keeps one documentation structure while supporting many presentation languages through locale bundles.
status: active
updated: 2026-09-17
kind: how-to
---

# Documentation localization

COS treats a document route and its translation as different concerns.

```text
Canonical page structure
        +
Locale bundle
        +
Runtime / generated facts
        ↓
Rendered documentation
```

English is the default/source locale. Other languages use the same URL path and select presentation text through `?lang=<locale>`.

## Files

```text
docs/
  content/pages/        # shared structured page definitions
  i18n/
    locales.json        # locale registry and routing policy
    en.json             # source/default text
    uk.json             # Ukrainian text
  <canonical routes>.md # one route tree only
```

Do not create `docs/en`, `docs/uk`, `docs/de` or similar language trees.

## Add a language

1. Add the locale to `docs/i18n/locales.json`.
2. Add `docs/i18n/<locale>.json` with `_meta.locale` matching the code.
3. Translate keys incrementally.
4. Run `npm run docs:localization:check`.

No route, sidebar or process definition is duplicated.

## Add a structured page

1. Add one JSON structure to `docs/content/pages/`.
2. Give the page a stable `id`, canonical `route` and stable block ids/translation keys.
3. Add every required key to the English bundle.
4. Add translations to other bundles when available.
5. Create one Markdown route wrapper with `<LocalizedPage page-id="..." />`.

Links and code-owned identifiers belong to page structure, not translation text.

## Translation fallback

The runtime resolves a key from the requested locale. If it is absent, the English value is rendered. CI reports coverage so incomplete translation remains visible without blocking the addition of a locale.

## URLs

```text
/docs/for-business/capabilities          # English canonical
/docs/for-business/capabilities?lang=uk  # Ukrainian
/docs/for-business/capabilities?lang=de  # German
```

The language selector persists the user's choice locally, but an explicit URL parameter always has priority.

## Validation

`npm run docs:localization:check` verifies:

- `en` is the default locale;
- `lang` is the query parameter;
- every registered locale has a bundle;
- the default bundle contains every structured-page key;
- page ids and routes are unique;
- path-based locale trees do not exist;
- the sidebar has one builder;
- entry pages use the shared localization renderer.
