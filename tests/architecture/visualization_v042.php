<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$required = [
    'app/Kernel/Module/CrossDomainContract.php',
    'app/Infrastructure/Visualization/Architecture/CrossDomainArchitectureGraphProvider.php',
    'tests/architecture/cross_domain_dependency_audit.php',
    'tests/unit/visualization_cross_domain_contracts.php',
    'docs/03-architecture/visualization-v0.4.2.md',
];
foreach ($required as $path) {
    $assert(is_file($root . '/' . $path), 'Missing Visualization V0.4.2 file: ' . $path);
}

$contributions = $read('app/Kernel/Module/ModuleContributions.php');
$assert(str_contains($contributions, 'cross_domain_contracts'), 'ModuleContributions must parse canonical cross-domain contracts.');
$assert(str_contains($contributions, 'CrossDomainContract::fromArray'), 'Cross-domain declarations must use a typed Kernel model.');

$sales = $read('app/Domains/Sales/module.php');
$property = $read('app/Domains/Property/module.php');
$assert(str_contains($sales, 'Domains\\\\Property\\\\Contract\\\\PropertyReferencePort'), 'Sales Property reference contract declaration missing.');
$assert(str_contains($property, 'PresentationSalesInterface'), 'Property -> Sales presentation contract declaration missing.');
$assert(str_contains($property, 'PropertyTourPublisherInterface'), 'Property -> Spatial contract declaration missing.');

$decorator = $read('app/Infrastructure/Visualization/Architecture/CrossDomainArchitectureGraphProvider.php');
$assert(str_contains($decorator, 'implements GraphProviderInterface'), 'Cross-domain graph provider must keep the Kernel GraphProvider boundary.');
$assert(str_contains($decorator, 'crossDomainContracts'), 'Cross-domain graph provider must consume canonical module contributions.');
foreach (['RecursiveDirectoryIterator', 'file_get_contents(', 'preg_match_all('] as $forbidden) {
    $assert(!str_contains($decorator, $forbidden), 'Runtime Architecture Graph must not infer dependencies from source scanning: ' . $forbidden);
}

$vocabulary = $read('app/Infrastructure/Visualization/Architecture/ArchitectureGraphVocabulary.php');
foreach (["TYPE_CONTRACT = 'contract'", "REL_REQUIRES_CONTRACT = 'requires_contract'", "REL_PROVIDES_CONTRACT = 'provides_contract'"] as $marker) {
    $assert(str_contains($vocabulary, $marker), 'Cross-domain graph vocabulary marker missing: ' . $marker);
}

$registry = $read('app/Infrastructure/Visualization/Architecture/ArchitectureProjectionRegistry.php');
$assert(str_contains($registry, "'contracts', 'Contracts'"), 'Dedicated Contracts projection is missing.');
$assert(str_contains($registry, 'REL_REQUIRES_CONTRACT'), 'Contracts projection must expose consumer edges.');
$assert(str_contains($registry, 'REL_PROVIDES_CONTRACT'), 'Contracts projection must expose provider edges.');

$bootstrap = $read('symfony/src/Infrastructure/Visualization/ArchitectureGraphProviderFactory.php');
$assert(str_contains($bootstrap, 'CrossDomainArchitectureGraphProvider'), 'Canonical graph service must be contract-aware.');
$assert(str_contains($bootstrap, 'FallbackArchitectureGraphProvider'), 'Canonical graph service must preserve the structural fallback.');

$workflow = $read('.github/workflows/visualization.yml');
$assert(str_contains($workflow, 'cross_domain_dependency_audit.php'), 'Cross-domain dependency audit is not enforced by Visualization CI.');
$assert(str_contains($workflow, 'visualization_v042.php'), 'V0.4.2 architecture gate is not enforced by CI.');

echo "Visualization V0.4.2 architecture boundary passed.\n";
