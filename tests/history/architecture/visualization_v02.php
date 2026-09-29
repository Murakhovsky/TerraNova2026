<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$kernelRoot = $root . '/app/Kernel/Visualization';
$provider = $root . '/app/Infrastructure/Visualization/Architecture/ArchitectureGraphProvider.php';
$bootstrap = $root . '/symfony/config/services.yaml';

if (!is_dir($kernelRoot) || !is_file($provider)) {
    throw new RuntimeException('Visualization V0.2 structure is incomplete.');
}

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($kernelRoot));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $source = file_get_contents($file->getPathname()) ?: '';
    foreach (['Cytoscape', 'Mermaid', 'Bpmn', 'Infrastructure\\', 'Interfaces\\'] as $forbidden) {
        if (str_contains($source, $forbidden)) {
            throw new RuntimeException(sprintf('Kernel Visualization leaked implementation dependency %s in %s.', $forbidden, $file->getFilename()));
        }
    }
}

$providerSource = file_get_contents($provider) ?: '';
foreach (['ModuleCatalog', 'DomainModuleRegistry', 'GraphProviderInterface'] as $required) {
    if (!str_contains($providerSource, $required)) {
        throw new RuntimeException('Architecture graph provider is missing canonical source: ' . $required);
    }
}
foreach (['Cytoscape', 'Mermaid', 'Bpmn'] as $forbidden) {
    if (str_contains($providerSource, $forbidden)) {
        throw new RuntimeException('Architecture graph provider is coupled to renderer: ' . $forbidden);
    }
}

$bootstrapSource = file_get_contents($bootstrap) ?: '';
foreach ([
    'runtime.architecture_graph_provider:',
    'Kernel\\Visualization\\Graph\\GraphProviderInterface:',
    'ArchitectureGraphProviderFactory',
] as $marker) {
    if (!str_contains($bootstrapSource, $marker)) {
        throw new RuntimeException('Architecture graph provider is not registered in Symfony composition: ' . $marker);
    }
}

echo "Visualization V0.2 architecture boundary passed.\n";
