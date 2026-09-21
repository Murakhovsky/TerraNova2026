<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$required = [
    'symfony/assets/styles/mobile-foundation.css',
    'symfony/src/Command/MobileFoundationSmokeCommand.php',
];

foreach ($required as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('Wave 12.16 Mobile Foundation artifact is missing: ' . $relative);
    }
}

$base = (string) file_get_contents($root . '/symfony/templates/base.html.twig');
if (!str_contains($base, 'viewport-fit=cover')) {
    throw new RuntimeException('Canonical Web viewport must enable safe-area coverage.');
}

$tokens = (string) file_get_contents($root . '/symfony/assets/styles/tokens.css');
if (!str_contains($tokens, '--cos-touch-target-min: 2.75rem')) {
    throw new RuntimeException('Canonical mobile touch target token is missing.');
}

$viewModel = (string) file_get_contents($root . '/symfony/src/Web/Experience/Workspace/WorkspaceViewModel.php');
foreach (['mobilePrimaryActions', 'mobileMenuActions'] as $marker) {
    if (!str_contains($viewModel, $marker)) {
        throw new RuntimeException('Workspace mobile action projection is missing: ' . $marker);
    }
}

$resolver = (string) file_get_contents($root . '/symfony/src/Web/Experience/Workspace/WorkspaceCompositionResolver.php');
foreach (['UIActionPlacement::MOBILE_PRIMARY', 'UIActionPlacement::MOBILE_MENU'] as $marker) {
    if (!str_contains($resolver, $marker)) {
        throw new RuntimeException('Workspace mobile placement resolution is missing: ' . $marker);
    }
}

$template = (string) file_get_contents($root . '/symfony/templates/components/experience/cos_workspace.html.twig');
foreach ([
    'workspace.mobilePrimaryActions',
    'workspace.mobileMenuActions',
    'cos-workspace__mobile-actions',
    'click->workspace-platform#action',
] as $marker) {
    if (!str_contains($template, $marker)) {
        throw new RuntimeException('Mobile Workspace surface is missing: ' . $marker);
    }
}

$styles = (string) file_get_contents($root . '/symfony/assets/styles/mobile-foundation.css');
foreach ([
    '100dvh',
    'env(safe-area-inset-top)',
    'env(safe-area-inset-bottom)',
    'var(--cos-touch-target-min)',
    '.cos-workspace__mobile-actions',
    'bottom: calc(3.75rem + env(safe-area-inset-bottom)',
    '@media (max-width: 760px)',
    '@media (max-width: 390px)',
] as $marker) {
    if (!str_contains($styles, $marker)) {
        throw new RuntimeException('Mobile foundation style contract is missing: ' . $marker);
    }
}
if (preg_match('/#[0-9a-fA-F]{3,8}\b/', $styles) === 1 || str_contains($styles, '--tn-')) {
    throw new RuntimeException('Mobile Foundation styles must use canonical COS semantic tokens.');
}

$data = (string) file_get_contents($root . '/symfony/assets/styles/data-grid.css');
foreach (['.cos-data-grid__card', '@media (max-width: 760px)', 'min-height: 44px'] as $marker) {
    if (!str_contains($data, $marker)) {
        throw new RuntimeException('Data Platform mobile reflow is missing: ' . $marker);
    }
}

$forms = (string) file_get_contents($root . '/symfony/assets/styles/forms.css');
foreach (['@media (max-width: 760px)', 'env(safe-area-inset-bottom)', 'min-height: 44px'] as $marker) {
    if (!str_contains($forms, $marker)) {
        throw new RuntimeException('Forms mobile contract is missing: ' . $marker);
    }
}

$interactions = (string) file_get_contents($root . '/symfony/assets/styles/interactions.css');
foreach (['.cos-drawer', '@media (max-width: 760px)', 'env(safe-area-inset-right)'] as $marker) {
    if (!str_contains($interactions, $marker)) {
        throw new RuntimeException('Interaction component mobile contract is missing: ' . $marker);
    }
}

foreach ([
    'symfony/assets/controllers/workspace_shell_controller.js',
    'symfony/assets/controllers/workspace_platform_controller.js',
] as $relative) {
    $browser = (string) file_get_contents($root . '/' . $relative);
    foreach (['navigator.userAgent', 'screen.width', 'localStorage', 'sessionStorage'] as $forbidden) {
        if (str_contains($browser, $forbidden)) {
            throw new RuntimeException('Mobile behavior contains forbidden device/state branching: ' . $relative . ' -> ' . $forbidden);
        }
    }
}

$smoke = (string) file_get_contents($root . '/symfony/src/Command/MobileFoundationSmokeCommand.php');
if (!str_contains($smoke, "name: 'cos:web:mobile:smoke'")) {
    throw new RuntimeException('Mobile Foundation runtime smoke is missing.');
}

echo "Wave 12.16 Mobile Foundation passed.\n";
