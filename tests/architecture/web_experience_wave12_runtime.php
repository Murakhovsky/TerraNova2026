<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

$requiredFiles = [
    'symfony/composer.json',
    'symfony/composer.lock',
    'symfony/config/bundles.php',
    'symfony/config/packages/framework.yaml',
    'symfony/config/packages/twig.yaml',
    'symfony/config/packages/twig_component.yaml',
    'symfony/config/routes/ux_live_component.yaml',
    'symfony/importmap.php',
    'symfony/assets/app.js',
    'symfony/assets/stimulus_bootstrap.js',
    'symfony/assets/controllers.json',
    'symfony/templates/base.html.twig',
    'symfony/src/Command/ExperiencePlatformSmokeCommand.php',
];

if (!is_dir($root . '/symfony/assets/controllers')) {
    throw new RuntimeException('Canonical Stimulus controllers directory is missing.');
}

foreach ($requiredFiles as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('Wave 12.1 runtime artifact is missing: ' . $relative);
    }
}

$composer = json_decode((string) file_get_contents($root . '/symfony/composer.json'), true, flags: JSON_THROW_ON_ERROR);
$requiredPackages = [
    'symfony/asset',
    'symfony/asset-mapper',
    'symfony/stimulus-bundle',
    'symfony/twig-bundle',
    'symfony/ux-live-component',
    'symfony/ux-turbo',
    'symfony/ux-twig-component',
    'twig/twig',
];

foreach ($requiredPackages as $package) {
    if (!isset($composer['require'][$package])) {
        throw new RuntimeException('Wave 12.1 Composer dependency is missing: ' . $package);
    }
}

$bundles = (string) file_get_contents($root . '/symfony/config/bundles.php');
foreach ([
    'TwigBundle::class',
    'StimulusBundle::class',
    'TwigComponentBundle::class',
    'LiveComponentBundle::class',
    'TurboBundle::class',
] as $bundle) {
    if (!str_contains($bundles, $bundle)) {
        throw new RuntimeException('Wave 12.1 bundle is not registered: ' . $bundle);
    }
}

$framework = (string) file_get_contents($root . '/symfony/config/packages/framework.yaml');
if (!str_contains($framework, 'asset_mapper:') || !str_contains($framework, '- assets/')) {
    throw new RuntimeException('AssetMapper path is not configured.');
}

$controllers = json_decode(
    (string) file_get_contents($root . '/symfony/assets/controllers.json'),
    true,
    flags: JSON_THROW_ON_ERROR,
);

if (($controllers['controllers']['@symfony/ux-live-component']['live']['enabled'] ?? false) !== true) {
    throw new RuntimeException('Live Component Stimulus controller is not enabled.');
}

if (($controllers['controllers']['@symfony/ux-turbo']['turbo-core']['enabled'] ?? false) !== true) {
    throw new RuntimeException('Turbo core controller is not enabled.');
}

$twigComponentConfig = (string) file_get_contents($root . '/symfony/config/packages/twig_component.yaml');
if (!str_contains($twigComponentConfig, "'App\\Web\\Experience\\Component\\':")) {
    throw new RuntimeException('Experience Twig Component namespace mapping is invalid.');
}

$routes = (string) file_get_contents($root . '/symfony/config/routes/ux_live_component.yaml');
if (!str_contains($routes, '@LiveComponentBundle/config/routes.php')) {
    throw new RuntimeException('Live Component route resource is not registered.');
}

$importmap = (string) file_get_contents($root . '/symfony/importmap.php');
if (!str_contains($importmap, "'entrypoint' => true")) {
    throw new RuntimeException('AssetMapper app import must be marked as an entrypoint.');
}

if (str_contains($importmap, "'preload' => true")) {
    throw new RuntimeException('Deprecated AssetMapper preload metadata must not be used.');
}

foreach ([
    "'@hotwired/stimulus'",
    "'@symfony/stimulus-bundle'",
    "'@hotwired/turbo'",
    "'@symfony/ux-live-component'",
] as $runtimeImport) {
    if (!str_contains($importmap, $runtimeImport)) {
        throw new RuntimeException('Experience browser runtime import is missing: ' . $runtimeImport);
    }
}

$appJs = (string) file_get_contents($root . '/symfony/assets/app.js');
if (!str_contains($appJs, "import './stimulus_bootstrap.js';")) {
    throw new RuntimeException('Stimulus bootstrap is not activated by the app entrypoint.');
}

$base = (string) file_get_contents($root . '/symfony/templates/base.html.twig');
if (!str_contains($base, "importmap('app')") || !str_contains($base, "asset('styles/app.css')")) {
    throw new RuntimeException('Canonical Twig base layout is not AssetMapper-enabled.');
}

if (!str_contains($base, 'ux_controller_link_tags()')) {
    throw new RuntimeException('Stimulus controller CSS autoimports are not rendered by the base layout.');
}

$dockerfile = (string) file_get_contents($root . '/docker/symfony/php/Dockerfile');
if (!str_contains($dockerfile, 'php bin/console importmap:install')) {
    throw new RuntimeException('Production image does not vendor ImportMap packages.');
}

echo "Wave 12.1 Symfony Experience runtime foundation passed.\n";
