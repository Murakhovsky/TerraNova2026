<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$manifest = require $root . '/app/Domains/Property/module.php';
$readme = file_get_contents($root . '/app/Domains/Property/README.md');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "[property-domain] {$message}\n");
        exit(1);
    }
};

$assert(($manifest['id'] ?? null) === 'property', 'manifest id must stay property');
$assert(($manifest['version'] ?? null) === '0.2.0', 'manifest version must be 0.2.0');
$assert(is_string($readme) && $readme !== '', 'Property README must exist');

foreach ([
    'PropertyAsset',
    'PropertyType',
    'PropertyLocation',
    'PropertyLifecycle',
    'PropertyRelation',
    'PropertyMedia',
    'PropertySubmission',
    '### PROPERTY',
    '### INVENTORY',
    '### LISTING',
] as $term) {
    $assert(str_contains($readme, $term), "canonical definition is missing {$term}");
}

$assert(str_contains((string) ($manifest['description'] ?? ''), 'Canonical registry'), 'manifest must describe Property as canonical registry');

echo "Property V0.2.0 domain definition: OK\n";
