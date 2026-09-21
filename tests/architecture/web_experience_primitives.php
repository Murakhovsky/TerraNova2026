<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

foreach ([
    'symfony/src/Web/Experience/Component/CosStatus.php',
    'symfony/templates/components/experience/cos_status.html.twig',
    'symfony/assets/styles/primitives.css',
    'symfony/assets/styles/interactions.css',
    'symfony/templates/experience/design_system_catalog.html.twig',
    'docs/03-architecture/cos-canonical-primitives.md',
] as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('PHASE 6 primitive artifact is missing: ' . $relative);
    }
}

$tokens = (string) file_get_contents($root . '/symfony/assets/styles/tokens.css');
foreach ([
    '--cos-state-hover-bg',
    '--cos-state-active-bg',
    '--cos-state-selected-bg',
    '--cos-state-selected-border',
    '--cos-state-focus-border',
    '--cos-state-error-border',
    '--cos-state-disabled-opacity',
    '--cos-state-loading-opacity',
] as $token) {
    if (!str_contains($tokens, $token)) {
        throw new RuntimeException('PHASE 6 state token is missing: ' . $token);
    }
}

$button = (string) file_get_contents($root . '/symfony/src/Web/Experience/Component/CosButton.php');
$iconButton = (string) file_get_contents($root . '/symfony/src/Web/Experience/Component/CosIconButton.php');
foreach ([$button, $iconButton] as $source) {
    foreach (['public bool $loading', 'public string $loadingLabel', 'public ?bool $pressed'] as $marker) {
        if (!str_contains($source, $marker)) {
            throw new RuntimeException('Canonical button state API is incomplete: ' . $marker);
        }
    }
}

foreach (['cos_button.html.twig', 'cos_icon_button.html.twig'] as $template) {
    $source = (string) file_get_contents($root . '/symfony/templates/components/experience/' . $template);
    foreach (['aria-busy', 'aria-pressed', 'cos-control-spinner'] as $marker) {
        if (!str_contains($source, $marker)) {
            throw new RuntimeException('Canonical button template state semantics are incomplete: ' . $template . ' / ' . $marker);
        }
    }
}

$status = (string) file_get_contents($root . '/symfony/src/Web/Experience/Component/CosStatus.php');
foreach (['positive', 'warning', 'danger', 'info'] as $tone) {
    if (!str_contains($status, "'$tone'")) {
        throw new RuntimeException('CosStatus tone contract is incomplete: ' . $tone);
    }
}
foreach (['Domains\\', 'Doctrine\\', '/api/'] as $forbidden) {
    if (str_contains($status, $forbidden)) {
        throw new RuntimeException('CosStatus contains forbidden dependency: ' . $forbidden);
    }
}

$primitives = (string) file_get_contents($root . '/symfony/assets/styles/primitives.css');
foreach ([
    'data-cos-state="hover"',
    'data-cos-state="focus"',
    'data-cos-state="active"',
    'data-cos-state="selected"',
    '[aria-busy="true"]',
    '[aria-invalid="true"]',
    '.cos-status',
    '.cos-status__dot',
    'prefers-reduced-motion',
] as $marker) {
    if (!str_contains($primitives, $marker)) {
        throw new RuntimeException('PHASE 6 primitive state CSS is incomplete: ' . $marker);
    }
}

$interactions = (string) file_get_contents($root . '/symfony/assets/styles/interactions.css');
foreach ([
    '[aria-current="page"]',
    '[aria-checked="true"]',
    '.cos-tabs__tab:active',
    '.cos-tabs__tab[data-cos-state="selected"]',
] as $marker) {
    if (!str_contains($interactions, $marker)) {
        throw new RuntimeException('PHASE 6 navigation state CSS is incomplete: ' . $marker);
    }
}

$catalog = (string) file_get_contents($root . '/symfony/templates/experience/design_system_catalog.html.twig');
foreach ([
    'PHASE 6 Canonical Primitives Pass',
    "['default', 'hover', 'focus', 'active']",
    'Selected',
    'Disabled',
    'Loading',
    'Error',
    '<twig:CosStatus',
    '<twig:CosCheckbox',
    '<twig:CosRadio',
    '<twig:CosSwitch',
] as $marker) {
    if (!str_contains($catalog, $marker)) {
        throw new RuntimeException('/dev/ui PHASE 6 state matrix is incomplete: ' . $marker);
    }
}

$docs = (string) file_get_contents($root . '/docs/03-architecture/cos-canonical-primitives.md');
foreach ([
    'Семантичні state tokens',
    'Детермінований visual QA',
    'Доступність',
    'Критерії завершення PHASE 6',
] as $marker) {
    if (!str_contains($docs, $marker)) {
        throw new RuntimeException('PHASE 6 primitive documentation is incomplete: ' . $marker);
    }
}

echo "PHASE 6 COS Canonical Primitives passed.\n";
