<?php
declare(strict_types=1);

use Domains\Diagnostic\Application\Service\MethodologyStudioService;
use Tests\Support\InMemoryMethodologyStudioRepository;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/Support/InMemoryMethodologyStudioRepository.php';

function v054(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = dirname(__DIR__, 2);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app/Domains/Diagnostic'));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $source = (string) file_get_contents($file->getPathname());
    v054(!str_contains($source, 'Domains\\Sales\\'), 'Diagnostic production code depends on Sales: ' . $file->getPathname());
}
$repo = new InMemoryMethodologyStudioRepository();
$studio = new MethodologyStudioService($repo);
$created = $studio->create('org-universal', [
    'slug' => 'finance-health',
    'name' => 'Finance Health',
    'domain' => 'Finance',
    'methodology_version' => '0.1.0',
], 'methodologist');
v054($created['pack_id'] === 'finance-health' && $repo->pack('org-universal', 'finance-health')['domain'] === 'Finance', 'Methodology Studio cannot create a non-Sales diagnostic pack.');

$repositorySource = (string) file_get_contents($root . '/app/Domains/Diagnostic/Infrastructure/Persistence/MySql/MysqlMethodologyStudioRepository.php');
v054(str_contains($repositorySource, 'diagnostic_assessment_results'), 'Diagnostic Runs do not consume structured assessment coverage.');
v054(!str_contains($repositorySource, "preg_match_all('/coverage"), 'Diagnostic Runs still parse coverage from presentation text.');
v054(!str_contains($repositorySource, '$.severity'), 'Diagnostic Runs still expect object-shaped finding severity.');
v054(str_contains($repositorySource, 'JSON_UNQUOTE(critical_row.value_json)'), 'Diagnostic Runs do not read scalar finding severity correctly.');

$evaluationSource = (string) file_get_contents($root . '/app/Domains/Diagnostic/Application/UseCase/EvaluateDiagnosticSession.php');
v054(str_contains($evaluationSource, 'assessmentProjection?->replace'), 'Evaluation does not persist the structured assessment projection.');

$hardening = (string) file_get_contents($root . '/frontend/features/diagnostics/methodology-studio-v054.js');
v054(str_contains($hardening, 'NOT accepts exactly one'), 'NOT condition cardinality is not guarded for human methodologists.');
v054(str_contains($hardening, "['score','coverage','confidence']"), 'Visual Rule Builder is missing assessment score/coverage/confidence choices.');
v054(str_contains($hardening, 'assessment.${row.entity_id}.${property}'), 'Visual Rule Builder does not emit assessment.* condition subjects.');

echo "Diagnostic V0.5.4 universal-platform hardening contract passed.\n";
