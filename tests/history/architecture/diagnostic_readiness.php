<?php
declare(strict_types=1);

use Infrastructure\Platform\Persistence\TableOwnership;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$required = [
    'app/Domains/Diagnostic/Model/DiagnosticPack.php',
    'app/Domains/Diagnostic/Model/DiagnosticSession.php',
    'app/Domains/Diagnostic/Model/Policy/PackPublicationPolicy.php',
    'app/Domains/Diagnostic/Model/Policy/SessionCompletionPolicy.php',
    'app/Domains/Diagnostic/Application/UseCase/EvaluateDiagnosticSession.php',
    'app/Domains/Diagnostic/Infrastructure/Persistence/MySql/MysqlDiagnosticPackRepository.php',
    'app/Domains/Diagnostic/Infrastructure/Persistence/MySql/MysqlDiagnosticSessionRepository.php',
    'app/Bootstrap/DiagnosticServices.php',
    'app/migrations/20260830_000020_diagnostic_domain.sql',
    'tests/smoke/diagnostic_domain.php',
    'tests/smoke/diagnostic_methodology.php',
    'tests/integration/diagnostic_persistence.php',
];
foreach ($required as $path) {
    if (!is_file($root . '/' . $path)) throw new RuntimeException('Diagnostic Phase 1 artifact is missing: ' . $path);
}

$diagnosticSource = '';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app/Domains/Diagnostic'));
foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') $diagnosticSource .= (string) file_get_contents($file->getPathname());
}
foreach (['Domains\\Sales', 'Domains\\Property', 'Domains\\Content', 'Interfaces\\', 'Phalcon\\', 'PDO;'] as $forbidden) {
    if (str_contains($diagnosticSource, $forbidden) && $forbidden !== 'PDO;') {
        throw new RuntimeException('Diagnostic core depends on another bounded context or delivery framework: ' . $forbidden);
    }
}

$publish = (string) file_get_contents($root . '/app/Domains/Diagnostic/Application/UseCase/PublishDiagnosticPack.php');
if (!str_contains($publish, 'PackPublicationPolicy') || !str_contains($publish, 'expectedLockVersion')) {
    throw new RuntimeException('Pack publication must validate methodology and use optimistic locking.');
}
$evaluate = (string) file_get_contents($root . '/app/Domains/Diagnostic/Application/UseCase/EvaluateDiagnosticSession.php');
foreach (['MethodologyEngine', 'DiagnosticSessionInputFactory', 'DiagnosticRecordType::Assessment', 'DiagnosticRecordType::Finding'] as $requiredToken) {
    if (!str_contains($evaluate, $requiredToken)) throw new RuntimeException('Evaluation bridge is incomplete: ' . $requiredToken);
}
$services = (string) file_get_contents($root . '/app/config/services_kernel.php');
if (!str_contains($services, 'DiagnosticServices.php')) throw new RuntimeException('Diagnostic services are absent from the common composition root.');

foreach (['diagnostic_packs', 'diagnostic_sessions', 'diagnostic_evidence', 'diagnostic_records'] as $table) {
    if (TableOwnership::ownerOf($table) !== 'Diagnostic') throw new RuntimeException('Diagnostic table ownership is missing: ' . $table);
}

echo "Diagnostic readiness passed: unified pack, policies, evaluation bridge, persistence, DI and tests are present.\n";
