<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};

$manifest=require $root.'/app/Domains/Diagnostic/module.php';
$assert(($manifest['version']??null)==='1.0.0','Diagnostic V1 release requires module version 1.0.0.');
$assert(($manifest['schema_version']??null)==='1.0.0','Diagnostic V1 release requires schema version 1.0.0.');
$assert(in_array('diagnostic.v1',$manifest['contributions']['capabilities']??[],true),'Diagnostic V1 capability missing.');

$runtime=$read('app/Domains/Diagnostic/Application/Service/DiagnosticRuntimeService.php');
foreach(['inputFromRuntime','appendFactRevision','appendAssessmentRevision','appendHypothesisRevision','appendRecommendationTransition','saveStateSnapshot'] as $needle){
    $assert(str_contains($runtime,$needle),'Diagnostic V1 runtime missing canonical semantic boundary: '.$needle);
}
$assert(str_contains($runtime,'$canonicalInput,false'),'Diagnostic V1 runtime must explicitly disable compatibility writes for canonical evaluation.');
$assert(!str_contains($runtime,'materializeInputs($organizationId,$sessionId'),'Canonical Diagnostic runtime must not materialize generic DiagnosticRecord inputs before evaluation.');

$evaluate=$read('app/Domains/Diagnostic/Application/UseCase/EvaluateDiagnosticSession.php');
foreach(['?DiagnosticInput $canonicalInput','bool $writeCompatibilityRecords = true','if ($writeCompatibilityRecords)'] as $needle){
    $assert(str_contains($evaluate,$needle),'Diagnostic V1 evaluation compatibility isolation missing: '.$needle);
}

$complete=$read('app/Domains/Diagnostic/Application/UseCase/CompleteDiagnosticSession.php');
foreach(['DiagnosticSemanticRepositoryInterface','latestAssessmentStatuses','completeValidated'] as $needle){
    $assert(str_contains($complete,$needle),'Diagnostic V1 completion is not driven by typed semantic assessments: '.$needle);
}

$semantic=$read('app/Domains/Diagnostic/Infrastructure/Persistence/MySql/MysqlDiagnosticSemanticRepository.php');
foreach(['diagnostic_fact_revisions','diagnostic_assessment_revisions','diagnostic_hypothesis_revisions','diagnostic_recommendation_transitions','diagnostic_state_snapshots'] as $table){
    $assert(str_contains($semantic,$table),'Diagnostic V1 semantic repository missing table: '.$table);
}

$compiler=$read('app/Domains/Diagnostic/Methodology/PackCompiler.php');
foreach(['COMPILER_VERSION','SCHEMA_VERSION','serializer->hash'] as $needle){
    $assert(str_contains($compiler,$needle),'Diagnostic V1 compiler metadata missing: '.$needle);
}

$assert(is_file($root.'/tests/smoke/diagnostic_v1_vertical_slice.php'),'Diagnostic V1 vertical slice is missing.');
$assert(is_file($root.'/tests/integration/diagnostic_persistence.php'),'Diagnostic V1 MySQL integration test is missing.');

echo "Diagnostic V1 release architecture: OK\n";
