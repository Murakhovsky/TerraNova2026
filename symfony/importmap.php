<?php

/**
 * Returns the importmap for this application.
 *
 * - "path" is a path inside the asset mapper system. Use the
 *     "debug:asset-map" command to see the full list of paths.
 *
 * - "entrypoint" (JavaScript only) set to true for any module that will
 *     be used as an "entrypoint" (and passed to the importmap() Twig function).
 *
 * The "importmap:require" command can be used to add new entries to this file.
 */
return [
    'app' => [
        'path' => 'app.js',
        'entrypoint' => true,
    ],
    'public_home' => [
        'path' => 'public_home.js',
        'entrypoint' => true,
    ],
    '@hotwired/stimulus' => [
        'version' => '3.2.2',
    ],
    '@symfony/stimulus-bundle' => [
        'path' => './vendor/symfony/stimulus-bundle/assets/dist/loader.js',
    ],
    '@hotwired/turbo' => [
        'version' => '7.3.0',
    ],
    '@symfony/ux-live-component' => [
        'path' => './vendor/symfony/ux-live-component/assets/dist/live_controller.js',
    ],
    'bootstrap' => [
        'version' => '5.3.8',
    ],
    '@popperjs/core' => [
        'version' => '2.11.8',
    ],
    'bootstrap/dist/css/bootstrap.min.css' => [
        'version' => '5.3.8',
        'type' => 'css',
    ],
    'chart.js' => [
        'version' => '4.5.1',
    ],
    '@kurkle/color' => [
        'version' => '0.3.4',
    ],
    'tabulator-tables' => [
        'version' => '6.5.3',
    ],
    'tabulator-tables/dist/css/tabulator.min.css' => [
        'version' => '6.5.3',
        'type' => 'css',
    ],
    'fullcalendar' => [
        'version' => '7.1.0',
    ],
    'preact/jsx-runtime' => [
        'version' => '10.29.8',
    ],
    'preact/compat' => [
        'version' => '10.29.8',
    ],
    'preact/compat/client' => [
        'version' => '10.29.8',
    ],
    '@full-ui/headless-calendar' => [
        'version' => '7.1.0',
    ],
    'preact' => [
        'version' => '10.29.8',
    ],
    'fullcalendar/skeleton.min.css' => [
        'version' => '7.1.0',
        'type' => 'css',
    ],
    'preact/hooks' => [
        'version' => '10.29.8',
    ],
    'temporal-polyfill/fns/ZonedDateTime' => [
        'version' => '1.0.4',
    ],
    'temporal-polyfill/fns/PlainDateTime' => [
        'version' => '1.0.4',
    ],
    'temporal-polyfill/fns/Instant' => [
        'version' => '1.0.4',
    ],
    'temporal-utils' => [
        'version' => '1.0.2',
    ],
    'temporal-utils/protected' => [
        'version' => '1.0.2',
    ],
    'temporal-utils/protected-error-messages' => [
        'version' => '1.0.2',
    ],
    'fullcalendar/daygrid' => [
        'version' => '7.1.0',
    ],
    'fullcalendar/interaction' => [
        'version' => '7.1.0',
    ],
    'fullcalendar/skeleton.css' => [
        'version' => '7.1.0',
        'type' => 'css',
    ],
    'sortablejs' => [
        'version' => '1.15.7',
    ],
    'flatpickr' => [
        'version' => '4.6.13',
    ],
    'flatpickr/dist/flatpickr.min.css' => [
        'version' => '4.6.13',
        'type' => 'css',
    ],
    'cytoscape' => [
        'version' => '3.34.3',
    ],
    'tom-select' => [
        'version' => '2.6.2',
    ],
    '@orchidjs/sifter' => [
        'version' => '1.1.0',
    ],
    '@orchidjs/unicode-variants' => [
        'version' => '1.1.2',
    ],
    'tom-select/dist/css/tom-select.default.min.css' => [
        'version' => '2.6.2',
        'type' => 'css',
    ],
    'tom-select/dist/css/tom-select.bootstrap5.css' => [
        'version' => '2.6.2',
        'type' => 'css',
    ],
    '@symfony/ux-translator' => [
        'path' => './vendor/symfony/ux-translator/assets/dist/translator_controller.js',
    ],
    'intl-messageformat' => [
        'version' => '10.7.18',
    ],
    'tslib' => [
        'version' => '2.8.1',
    ],
    '@formatjs/fast-memoize' => [
        'version' => '2.2.7',
    ],
    '@formatjs/icu-messageformat-parser' => [
        'version' => '2.11.4',
    ],
    '@formatjs/icu-skeleton-parser' => [
        'version' => '1.8.16',
    ],
];
