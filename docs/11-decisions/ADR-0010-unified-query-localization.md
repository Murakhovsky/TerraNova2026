---
title: ADR-0010 — Unified query-parameter localization
description: One canonical documentation route tree with English default text and locale bundles selected through ?lang=<locale>.
status: accepted
updated: 2026-09-17
kind: adr
---

# ADR-0010 — Unified query-parameter localization

## Decision

COS documentation has one canonical page and navigation structure. Language is presentation state, not document identity.

- English (`en`) is the default/source locale.
- The canonical URL has no language prefix or query parameter.
- Non-default locales are selected with `?lang=<locale>`, for example `/docs/for-business/capabilities?lang=uk`.
- `docs/en/**`, `docs/uk/**`, `docs/de/**` and equivalent per-language page trees are forbidden.
- Page structure lives once in canonical Markdown or `docs/content/pages/*.json`.
- Translatable text lives in `docs/i18n/<locale>.json`.
- Machine-owned facts, process definitions, capabilities, contracts, code references, generated reference and diagrams are not duplicated by locale.

## Resolution order

The runtime resolves language in this order:

1. valid `?lang=<locale>` from the URL;
2. the user's previously selected locale from local storage;
3. English.

The URL always wins. Selecting English removes `lang` from the URL because English is canonical.

## Fallback

Missing text in a requested locale falls back to English at the individual translation-key level. This allows a new locale to be introduced before every article is translated without creating a second route tree.

Translation completeness is observable debt, not a reason to duplicate pages.

## SEO

The canonical link points to the English URL without `lang`. Alternate languages are exposed through `hreflang` URLs using the same path and their query parameter. `x-default` points to the English canonical URL.

## Authoring rule

A new language adds a registry entry and a locale bundle. It must not add routes, sidebar definitions or copies of Markdown files.

A new localized structured page adds:

1. one page structure;
2. English text keys;
3. optional translations for any supported locale;
4. one canonical route wrapper.

## Branch model

Documentation framework development occurs on the long-lived `documentation` branch. Runtime/domain facts continue to be read from `main` when needed. The documentation branch is validated by CI but is never deployed directly. Deployment remains restricted to `main` after merge.
