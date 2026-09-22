<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$manifest = $root . '/tests/architecture/contracts/web_platform_v1.php';

if (!is_file($manifest)) {
    throw new RuntimeException('Web Platform v1 contract manifest is missing.');
}

/** @var list<string> $contracts */
$contracts = require $manifest;

if ($contracts === [] || count($contracts) !== count(array_unique($contracts))) {
    throw new RuntimeException('Web Platform v1 contract manifest must be non-empty and unique.');
}

foreach ($contracts as $relative) {
    if (!is_string($relative) || $relative === '' || str_starts_with($relative, '/')) {
        throw new RuntimeException('Invalid Web Platform v1 contract path.');
    }
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('Frozen Web Platform v1 contract is missing: ' . $relative);
    }
}

$adrPath = $root . '/docs/11-decisions/ADR-0011-web-platform-v1-freeze.md';
if (!is_file($adrPath)) {
    throw new RuntimeException('ADR-0011 Web Platform v1 freeze is missing.');
}

$adr = (string) file_get_contents($adrPath);
foreach ([
    'Web Experience Platform v1 заморожується',
    'tests/architecture/contracts/web_platform_v1.php',
    'новий або оновлений ADR',
    'change-control',
    'production Sales cutover',
] as $marker) {
    if (!str_contains($adr, $marker)) {
        throw new RuntimeException('ADR-0011 freeze policy is incomplete: ' . $marker);
    }
}

$index = (string) file_get_contents($root . '/docs/11-decisions/README.md');
if (!str_contains($index, 'ADR-0011-web-platform-v1-freeze.md')) {
    throw new RuntimeException('ADR-0011 is missing from the decisions index.');
}

// Sales is the compatibility anchor that makes v1 a production contract, not a dev-only declaration.
$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
foreach ([
    'controller: App\\Web\\Sales\\SalesWorkspaceController::dashboard',
    'controller: App\\Web\\Sales\\SalesWorkspaceController::leads',
    'controller: App\\Web\\Sales\\SalesWorkspaceController::lead',
] as $marker) {
    if (!str_contains($routes, $marker)) {
        throw new RuntimeException('Web Platform v1 freeze requires completed Sales cutover: ' . $marker);
    }
}
foreach ([
    'app/Interfaces/Web/View/sales/dashboard.phtml',
    'app/Interfaces/Web/View/sales/leads.phtml',
    'symfony/src/Web/Sales/SalesReferenceController.php',
] as $retired) {
    if (file_exists($root . '/' . $retired)) {
        throw new RuntimeException('Web Platform v1 freeze requires retired Sales ownership to stay deleted: ' . $retired);
    }
}

$baseSha = trim((string) getenv('COS_WEB_PLATFORM_FREEZE_BASE_SHA'));
if ($baseSha !== '' && preg_match('/^[a-f0-9]{40}$/', $baseSha) === 1 && !preg_match('/^0{40}$/', $baseSha)) {
    $verify = [];
    $verifyCode = 0;
    exec(
        'git -C ' . escapeshellarg($root) . ' cat-file -e ' . escapeshellarg($baseSha . '^{commit}') . ' 2>&1',
        $verify,
        $verifyCode,
    );
    if ($verifyCode !== 0) {
        throw new RuntimeException(
            'Freeze base SHA is unavailable locally. CI must fetch it before running change-control: ' . $baseSha,
        );
    }

    $pathspec = implode(' ', array_map('escapeshellarg', $contracts));
    $changedOutput = [];
    $changedCode = 0;
    exec(
        'git -C ' . escapeshellarg($root)
        . ' diff --name-only ' . escapeshellarg($baseSha . '...HEAD')
        . ' -- ' . $pathspec . ' 2>&1',
        $changedOutput,
        $changedCode,
    );
    if ($changedCode !== 0) {
        throw new RuntimeException('Unable to inspect frozen Web Platform contract diff.');
    }

    $changedContracts = array_values(array_filter(array_map('trim', $changedOutput)));
    if ($changedContracts !== []) {
        $adrOutput = [];
        $adrCode = 0;
        exec(
            'git -C ' . escapeshellarg($root)
            . ' diff --name-only ' . escapeshellarg($baseSha . '...HEAD')
            . ' -- ' . escapeshellarg('docs/11-decisions/ADR-*.md') . ' 2>&1',
            $adrOutput,
            $adrCode,
        );
        if ($adrCode !== 0) {
            throw new RuntimeException('Unable to inspect ADR diff for Web Platform change-control.');
        }

        $changedAdrs = array_values(array_filter(array_map('trim', $adrOutput)));
        if ($changedAdrs === []) {
            throw new RuntimeException(
                "Frozen Web Platform v1 contracts changed without an ADR in the same change set:\n- "
                . implode("\n- ", $changedContracts),
            );
        }

        echo "Web Platform v1 protected contracts changed with ADR governance: "
            . implode(', ', $changedAdrs) . "\n";
    }
}

echo sprintf("Web Platform v1 Freeze passed: %d protected contracts.\n", count($contracts));
