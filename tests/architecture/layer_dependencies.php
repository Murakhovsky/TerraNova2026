<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

/** @return list<string> */
function phpFiles(string $directory): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') $files[] = $file->getPathname();
    }
    return $files;
}

/** @param list<string> $forbiddenPrefixes */
function assertNoDependencies(string $directory, array $forbiddenPrefixes): void
{
    foreach (phpFiles($directory) as $file) {
        $source = file_get_contents($file);
        foreach ($forbiddenPrefixes as $prefix) {
            $pattern = '/^use\s+' . preg_quote($prefix, '/') . '\\\\/m';
            if (preg_match($pattern, $source)) {
                throw new RuntimeException(sprintf(
                    '%s must not depend on %s (%s).',
                    str_replace(dirname($directory) . DIRECTORY_SEPARATOR, '', $directory),
                    $prefix,
                    str_replace($GLOBALS['root'] . DIRECTORY_SEPARATOR, '', $file),
                ));
            }
        }
    }
}

assertNoDependencies($root . '/app/Kernel', ['Domains', 'Infrastructure', 'Interfaces', 'Modules', 'Common', 'Phalcon']);
foreach (glob($root . '/app/Domains/*', GLOB_ONLYDIR) ?: [] as $domainDirectory) {
    foreach (['AI', 'Application', 'Automation', 'Domain', 'Methodology', 'Model'] as $coreArea) {
        $directory = $domainDirectory . '/' . $coreArea;
        if (is_dir($directory)) {
            assertNoDependencies($directory, ['Infrastructure', 'Interfaces', 'Modules', 'Common', 'Phalcon', 'PDO']);
        }
    }
}
assertNoDependencies($root . '/app/Infrastructure', ['Interfaces', 'Modules', 'Common']);
foreach (['Web', 'Api', 'Cli', 'Shared'] as $interfaceArea) {
    $directory = $root . '/app/Interfaces/' . $interfaceArea;
    if (is_dir($directory)) {
        assertNoDependencies($directory, ['Infrastructure', 'Modules']);
    }
}

// Longman commands are framework adapters and may call the canonical Telegram persistence/integration adapters.
assertNoDependencies($root . '/app/Interfaces/Telegram', ['Modules', 'Common', 'Infrastructure\\Legacy']);

$clientCaseFacade = (string) file_get_contents($root . '/app/Interfaces/Web/Service/ClientCaseService.php');
foreach (['PdoConnection', 'PDO', '->prepare(', '->transactional(', 'EventBus', 'ClientCaseCreated::', 'ClientCaseChanged::', 'DealStageChanged::', 'LeadChanged::'] as $forbidden) {
    if (str_contains($clientCaseFacade, $forbidden)) {
        throw new RuntimeException('ClientCaseService must remain a thin compatibility facade; forbidden dependency: ' . $forbidden);
    }
}

$inboundResolver = (string) file_get_contents($root . '/app/Bootstrap/InboundCaseResolverAdapter.php');
if (preg_match('/^use\s+Modules\\\\/m', $inboundResolver)) {
    throw new RuntimeException('Inbound case resolution must call Sales use cases without routing through legacy Modules.');
}

foreach (phpFiles($root . '/app/Kernel') as $file) {
    $source = file_get_contents($file);
    if (preg_match('/^use\s+PDO\s*;/m', $source)
        || str_contains($source, 'new PDO(')
        || str_contains($source, 'new \\PDO(')
    ) {
        throw new RuntimeException('Kernel must access persistence through contracts: ' . $file);
    }
}

foreach (phpFiles($root . '/app/Interfaces/Api/Controller') as $file) {
    $source = (string) file_get_contents($file);
    if (preg_match('/public\s+function\s+\w+Action\s*\([^)]*\)\s*:\s*void/', $source)) {
        throw new RuntimeException('API actions must return the Phalcon response so JSON bodies reach HTTP clients: ' . $file);
    }
}

$requiredSalesAreas = ['Application', 'Automation', 'Bootstrap', 'Infrastructure', 'Model'];
foreach ($requiredSalesAreas as $area) {
    if (!is_dir($root . '/app/Domains/Sales/' . $area)) {
        throw new RuntimeException(sprintf('Sales domain is missing its %s area.', $area));
    }
}

// Diagnostic uses Model as its domain-model area. Schemas are documentation/data contracts,
// not a PHP architectural layer; keep this gate aligned with the canonical Diagnostic layout.
$requiredDiagnosticAreas = ['AI', 'Application', 'Automation', 'Infrastructure', 'Methodology', 'Model'];
foreach ($requiredDiagnosticAreas as $area) {
    if (!is_dir($root . '/app/Domains/Diagnostic/' . $area)) {
        throw new RuntimeException(sprintf('Diagnostic domain is missing its %s area.', $area));
    }
}

$diagnosticLlmGateway = (string) file_get_contents($root . '/app/Domains/Diagnostic/Infrastructure/AI/StructuredLlmAiGateway.php');
if (str_contains($diagnosticLlmGateway, 'Kernel\\Agent\\')) {
    throw new RuntimeException('Diagnostic LLM gateway must use the provider-neutral Kernel\\Llm port, not the Agent runtime contract.');
}
if (!str_contains($diagnosticLlmGateway, 'Kernel\\Llm\\StructuredLlmClientInterface')) {
    throw new RuntimeException('Diagnostic LLM gateway must depend on Kernel\\Llm\\StructuredLlmClientInterface.');
}
if (!str_contains($diagnosticLlmGateway, 'useCase: $operation->operationId')) {
    throw new RuntimeException('Diagnostic LLM gateway must route through the semantic operation id.');
}

$httpLlmClient = (string) file_get_contents($root . '/app/Infrastructure/Llm/HttpStructuredLlmClient.php');
foreach (['LlmClientInterface', 'StructuredLlmClientInterface'] as $contract) {
    if (!str_contains($httpLlmClient, $contract)) {
        throw new RuntimeException('HTTP LLM transport must implement both Agent compatibility and provider-neutral structured LLM contracts.');
    }
}

echo "Architecture boundaries passed: Kernel/Domains are independent and LLM transport is shared without leaking provider or Agent semantics into Diagnostic.\n";
