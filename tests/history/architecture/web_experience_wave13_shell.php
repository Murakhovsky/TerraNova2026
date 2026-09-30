<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$shellPath = $root . '/symfony/assets/styles/shell.css';
$tokensPath = $root . '/symfony/assets/styles/tokens.css';
$templatePath = $root . '/symfony/templates/experience/workspace_shell.html.twig';

foreach ([$shellPath, $tokensPath, $templatePath] as $path) {
    if (!is_file($path)) {
        throw new RuntimeException('Wave 13.1 shell artifact is missing: ' . $path);
    }
}

$shell = (string) file_get_contents($shellPath);
$tokens = (string) file_get_contents($tokensPath);

foreach ([
    'var(--cos-layout-sidebar-width)',
    'var(--cos-layout-page-max)',
    'var(--cos-layout-page-padding)',
    'var(--cos-layout-page-padding-tablet)',
    'var(--cos-layout-page-padding-mobile)',
    'var(--cos-shell-topbar-height)',
    'var(--cos-shell-topbar-height-mobile)',
    'var(--cos-shell-mobile-nav-height)',
    'var(--cos-shell-drawer-width)',
    'var(--cos-shell-drawer-max-inline-size)',
    'var(--cos-z-navigation)',
    'var(--cos-z-header)',
    'var(--cos-z-overlay)',
    '@media (max-width: 1050px)',
    '@media (max-width: 650px)',
] as $contract) {
    if (!str_contains($shell, $contract)) {
        throw new RuntimeException('Wave 13.1 canonical shell contract is missing: ' . $contract);
    }
}

foreach ([
    '--cos-shell-sidebar-width:',
    '--cos-shell-topbar-height:',
    'width: min(100%, 112rem)',
    '@media (max-width: 1080px)',
    '@media (max-width: 760px)',
    'width: min(19rem, 86vw)',
    'padding-bottom: 4rem',
] as $legacyOwnership) {
    if (str_contains($shell, $legacyOwnership)) {
        throw new RuntimeException('Application Shell still owns frozen layout geometry locally: ' . $legacyOwnership);
    }
}

foreach ([
    '--cos-layout-sidebar-width: 16rem;',
    '--cos-layout-page-max: 112rem;',
    '--cos-shell-topbar-height: 4.25rem;',
    '--cos-shell-topbar-height-mobile: 3.75rem;',
    '--cos-shell-mobile-nav-height: 3.75rem;',
    '--cos-shell-drawer-width: 19rem;',
    '--cos-shell-drawer-max-inline-size: 86vw;',
] as $token) {
    if (!str_contains($tokens, $token)) {
        throw new RuntimeException('Application Shell layout token ownership is incomplete: ' . $token);
    }
}

$template = (string) file_get_contents($templatePath);
foreach ([
    'shell.primaryNavigation',
    'shell.utilityNavigation',
    'shell.breadcrumbs',
    'shell.commands',
    'shell.notificationCount',
    'shell.activityCount',
    'shell.connectionState.value',
    'shell.aiAvailable',
] as $context) {
    if (!str_contains($template, $context)) {
        throw new RuntimeException('Application Shell context contract regressed: ' . $context);
    }
}

echo "Wave 13.1 Application Shell geometry and context contract passed.\n";
