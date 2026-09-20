<?php

declare(strict_types=1);

/**
 * Canonical AssetMapper import map for the COS Experience Platform.
 *
 * Wave 12.1 activates the local app entrypoint. Stimulus/Turbo/Live browser
 * activation is completed in Wave 12.2 after deterministic JS vendoring.
 */
return [
    'app' => [
        'path' => 'app.js',
        'preload' => true,
    ],
];
