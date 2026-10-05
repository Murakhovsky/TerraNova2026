<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2).'/symfony/src/Engineering/Application/DomainDevelopment/EngineeringDomainContextCompressor.php';

use App\Engineering\Application\DomainDevelopment\EngineeringDomainContextCompressor;

$compressor = new EngineeringDomainContextCompressor(fullArtifactByteLimit: 128, maxSectionItems: 2);

$artifact = [
    'id' => 'a-1',
    'type' => 'DOMAIN_ARCHITECTURE',
    'version' => 3,
    'content_hash' => hash('sha256', 'fixture'),
    'content' => [
        'name' => 'Capital Markets',
        'module_structure' => [
            ['name' => 'one'],
            ['name' => 'two'],
            ['name' => 'three'],
        ],
        'dependency_rules' => ['a','b','c'],
        'large' => str_repeat('x', 500),
    ],
];

$out = $compressor->artifact($artifact, ['module_structure','dependency_rules']);
if (($out['full_artifact'] ?? 'missing') !== null) throw new RuntimeException('Large artifact was not compressed.');
if (($out['hash'] ?? null) !== $artifact['content_hash']) throw new RuntimeException('Artifact hash was not preserved.');
if (($out['canonical_summary']['name'] ?? null) !== 'Capital Markets') throw new RuntimeException('Canonical summary lost artifact identity.');
if (count($out['relevant_sections']['module_structure'] ?? []) !== 2) throw new RuntimeException('Relevant section was not bounded.');

$index = $compressor->repositoryIndex([
    'repository' => 'Murakhovsky/TerraNova2026',
    'revision' => 'abc',
    'hash' => 'idx-hash',
    'counts' => ['classes' => 3],
    'classes' => [['path'=>'A.php'],['path'=>'B.php'],['path'=>'C.php']],
    'interfaces' => [], 'services' => [], 'entities' => [], 'routes' => [], 'migrations' => [], 'tests' => [], 'modules' => [], 'dependencies' => [],
]);
if (($index['full_artifact'] ?? 'missing') !== null) throw new RuntimeException('Repository index must use compressed projection.');
if (count($index['relevant_sections']['classes'] ?? []) !== 2) throw new RuntimeException('Repository index section was not bounded.');
if (($index['canonical_summary']['revision'] ?? null) !== 'abc') throw new RuntimeException('Repository index revision was lost.');

echo "Engineering Domain context compression passed.\n";
