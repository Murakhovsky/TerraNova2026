<?php

declare(strict_types=1);

/**
 * Canonical AssetMapper import map for the COS Experience Platform.
 *
 * Remote packages are downloaded during image build with importmap:install,
 * then served locally from compiled AssetMapper output. Production browsers
 * never depend on a third-party CDN at runtime.
 */
return [
    'app' => [
        'path' => 'app.js',
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
];
