<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
foreach ([
    'resources/experience/golden-decisions.yaml',
    'symfony/src/Web/Experience/Golden/GoldenDecisionRegistry.php',
    'symfony/src/Command/GoldenDecisionAuditCommand.php',
] as $file) {
    if (!is_file($root.'/'.$file)) {
        throw new RuntimeException('Golden decision artifact missing: '.$file);
    }
}

$manifest = (string) file_get_contents($root.'/resources/experience/golden-decisions.yaml');
if (substr_count($manifest, 'decision: PENDING') !== 8) {
    throw new RuntimeException('Golden decisions must begin with eight explicit PENDING entries.');
}

$registry = (string) file_get_contents($root.'/symfony/src/Web/Experience/Golden/GoldenDecisionRegistry.php');
foreach ([
    "['PENDING', 'ACCEPT', 'REQUEST_CHANGES']",
    'ACCEPT requires actor, decided_at and evidence',
    'REQUEST_CHANGES requires actor, decided_at and note',
    'acceptedCount',
] as $marker) {
    if (!str_contains($registry, $marker)) {
        throw new RuntimeException('Golden decision ledger contract missing: '.$marker);
    }
}

echo "Golden source-controlled decision ledger contract OK\n";
