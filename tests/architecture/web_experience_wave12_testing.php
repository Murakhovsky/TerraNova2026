<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$required = [
    'tests/unit/ui_catalog_registry.php',
    'tests/functional/web_platform_contract.sh',
    'tests/component/web_experience_components.php',
    'symfony/tests/Panther/WebExperiencePantherTest.php',
    'tests/browser/web_platform_quality.mjs',
    'docs/03-architecture/web-testing.md',
];

foreach ($required as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('Wave 12.23 testing artifact is missing: ' . $relative);
    }
}

$browser = (string) file_get_contents($root . '/tests/browser/web_platform_quality.mjs');
foreach ([
    "name: 'desktop'",
    "name: 'mobile'",
    'accessibilityIssues',
    'assertVisual',
    "reducedMotion: 'reduce'",
    'horizontal overflow',
    'keyboard focus',
] as $marker) {
    if (!str_contains($browser, $marker)) {
        throw new RuntimeException('Wave 12.23 browser quality coverage is incomplete: ' . $marker);
    }
}

$functional = (string) file_get_contents($root . '/tests/functional/web_platform_contract.sh');
foreach ([
    '/health/dependencies',
    '/auth/login',
    '/property/catalog',
    '/dev/ui',
    '/cabinet',
    'location: /auth/login',
] as $marker) {
    if (!str_contains($functional, $marker)) {
        throw new RuntimeException('Wave 12.23 functional coverage is incomplete: ' . $marker);
    }
}

$component = (string) file_get_contents($root . '/tests/component/web_experience_components.php');
foreach (['Cos*.php', 'template:', 'Domains\\', 'Doctrine\\'] as $marker) {
    if (!str_contains($component, $marker)) {
        throw new RuntimeException('Wave 12.23 component coverage is incomplete: ' . $marker);
    }
}

$panther = (string) file_get_contents($root . '/symfony/tests/Panther/WebExperiencePantherTest.php');
foreach (['PantherTestCase', 'createPantherClient', '/auth/login', '/property/catalog', '/dev/ui'] as $marker) {
    if (!str_contains($panther, $marker)) {
        throw new RuntimeException('Wave 12.23 Panther suite contract is incomplete: ' . $marker);
    }
}

$workflow = (string) file_get_contents($root . '/.github/workflows/symfony-bootstrap.yml');
foreach ([
    'Wave 12.23 architecture gate',
    'Wave 12.23 component contract',
    'Wave 12.23 functional contract',
    'Wave 12.23 browser quality',
] as $marker) {
    if (!str_contains($workflow, $marker)) {
        throw new RuntimeException('Wave 12.23 CI coverage is incomplete: ' . $marker);
    }
}

echo "Wave 12.23 Testing passed.\n";
