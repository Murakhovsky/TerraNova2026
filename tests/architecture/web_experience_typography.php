<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

foreach ([
    'symfony/assets/styles/typography.css',
    'symfony/assets/styles/tokens.css',
    'symfony/templates/experience/design_system_catalog.html.twig',
    'docs/03-architecture/cos-typography.md',
] as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('PHASE 3 typography artifact is missing: ' . $relative);
    }
}

$tokens = (string) file_get_contents($root . '/symfony/assets/styles/tokens.css');
foreach ([
    '--cos-font-candidate-geist',
    '--cos-font-candidate-inter',
    '--cos-font-candidate-plex',
    '--cos-font-weight-regular',
    '--cos-font-weight-medium',
    '--cos-font-weight-strong',
    '--cos-line-height-tight',
    '--cos-line-height-ui',
    '--cos-line-height-copy',
    '--cos-letter-spacing-data',
] as $marker) {
    if (!str_contains($tokens, $marker)) {
        throw new RuntimeException('PHASE 3 typography token is missing: ' . $marker);
    }
}

$typography = (string) file_get_contents($root . '/symfony/assets/styles/typography.css');
foreach ([
    '.cos-type-display',
    '.cos-type-title',
    '.cos-type-heading',
    '.cos-type-body',
    '.cos-numeric',
    '.cos-money',
    '.cos-type-candidate--geist',
    '.cos-type-candidate--inter',
    '.cos-type-candidate--plex',
    'font-variant-numeric: tabular-nums lining-nums;',
] as $marker) {
    if (!str_contains($typography, $marker)) {
        throw new RuntimeException('PHASE 3 typography CSS contract is incomplete: ' . $marker);
    }
}

$appCss = (string) file_get_contents($root . '/symfony/assets/styles/app.css');
$tokensImport = strpos($appCss, "@import './tokens.css';");
$typographyImport = strpos($appCss, "@import './typography.css';");
$primitivesImport = strpos($appCss, "@import './primitives.css';");

if ($tokensImport === false || $typographyImport === false || $primitivesImport === false) {
    throw new RuntimeException('PHASE 3 typography import chain is incomplete.');
}
if (!($tokensImport < $typographyImport && $typographyImport < $primitivesImport)) {
    throw new RuntimeException('typography.css must load after tokens and before canonical components.');
}

$catalog = (string) file_get_contents($root . '/symfony/templates/experience/design_system_catalog.html.twig');
foreach ([
    'PHASE 3 Typography Lab',
    'Geist',
    'Inter',
    'IBM Plex Sans',
    '123,450 €',
    'Company Operating System',
    'Потенційний клієнт',
    'Продаж житлового комплексу',
    'Pipeline Forecast',
    'cos-numeric',
] as $marker) {
    if (!str_contains($catalog, $marker)) {
        throw new RuntimeException('/dev/ui typography lab is incomplete: ' . $marker);
    }
}

$docs = (string) file_get_contents($root . '/docs/03-architecture/cos-typography.md');
foreach ([
    'Базовий production-режим',
    'Лабораторія кандидатів',
    'Фінансова та числова типографіка',
    'Критерії завершення PHASE 3',
] as $marker) {
    if (!str_contains($docs, $marker)) {
        throw new RuntimeException('PHASE 3 typography documentation is incomplete: ' . $marker);
    }
}

echo "PHASE 3 COS Typography contract passed.\n";
