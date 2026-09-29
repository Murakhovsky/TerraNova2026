<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$read=static function(string $path)use($root):string{$f=$root.'/'.$path;if(!is_file($f))throw new RuntimeException('Missing Wave 5 file: '.$path);return(string)file_get_contents($f);};
$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};

$routes=$read('symfony/config/routes.yaml');
foreach(['/api/v1/diagnostics','/api/v1/diagnostics/{id}','/api/v1/diagnostics/{id}/interview/next','/api/v1/diagnostics/{id}/interview/answers','/api/v1/diagnostics/{id}/evidence','/api/v1/diagnostics/{id}/complete','/api/v1/diagnostics/{id}/assessment','/api/v1/diagnostics/{id}/findings','/api/v1/diagnostics/{id}/recommendations','/api/v1/diagnostics/{id}/recommendations/{recommendationId}/action'] as $route)$assert(str_contains($routes,$route),'Missing Diagnostic route: '.$route);

$controller=$read('symfony/src/Http/Api/V1/Controller/DiagnosticController.php');
foreach(['CommandBusInterface','QueryBusInterface','TenantContextProviderInterface','LegacySessionCsrfValidator','ActiveModuleResolver','TenantPermissions::MANAGE','X-Idempotency-Key','_cos_correlation_id'] as $needle)$assert(str_contains($controller,$needle),'Controller boundary missing: '.$needle);
$assert(!str_contains($controller,'PDO'),'Diagnostic controller must not access PDO.');
$assert(!str_contains($controller,'Domains\\'),'Diagnostic API controller must not depend on Domains directly.');
foreach(['owner_id_required','due_at_required','invalid_workflow_code'] as $needle)$assert(str_contains($controller,$needle),'Recommendation operational assignment validation missing: '.$needle);

$pipeline=$read('app/Domains/Diagnostic/Application/Service/DiagnosticEvidencePipeline.php');
foreach(['EvidenceType::tryFrom','CaptureDiagnosticEvidence','contradictions','facts_ingested','metrics_ingested'] as $needle)$assert(str_contains($pipeline,$needle),'Evidence pipeline missing: '.$needle);

$runtime=$read('app/Domains/Diagnostic/Application/Service/DiagnosticRuntimeService.php');
$assert(str_contains($runtime,"'idempotency_key'=>"),'Interview answer idempotency is missing.');
$assert(str_contains($runtime,'different interview answer'),'Interview idempotency payload conflict is missing.');
$startHandler=$read('symfony/src/Application/Diagnostic/Command/StartDiagnosticCommandHandler.php');
$assert(str_contains($startHandler,'idempotency_request_hash'),'Diagnostic creation request fingerprint is missing.');
$assert(str_contains($pipeline,'idempotency_request_hash'),'Evidence request fingerprint is missing.');
$assert(str_contains($pipeline,'different evidence payload'),'Evidence idempotency payload conflict is missing.');
$assert(!str_contains($runtime,'saveState($organizationId,$sessionId,$row[\'state\'],$next?->questionId'),'GET next-question must be read-only.');

foreach([
    'StartDiagnosticCommandHandler.php',
    'AnswerDiagnosticInterviewCommandHandler.php',
    'CaptureDiagnosticEvidenceCommandHandler.php',
    'CompleteDiagnosticCommandHandler.php',
    'AcceptDiagnosticRecommendationCommandHandler.php',
] as $handlerFile){
    $source=$read('symfony/src/Application/Diagnostic/Command/'.$handlerFile);
    $assert(str_contains($source,'TransactionManagerInterface'),'Wave 5 write handler lacks transaction boundary: '.$handlerFile);
    $assert(str_contains($source,'->transactional('),'Wave 5 write handler does not execute transactionally: '.$handlerFile);
}
$acceptHandler=$read('symfony/src/Application/Diagnostic/Command/AcceptDiagnosticRecommendationCommandHandler.php');
foreach(['IdentityResolverInterface','UserId::fromString','active member of this organization'] as $needle)$assert(str_contains($acceptHandler,$needle),'Recommendation owner tenant validation missing: '.$needle);

$accept=$read('app/Domains/Diagnostic/Application/UseCase/AcceptDiagnosticRecommendation.php');
foreach(['ActionService','IMPLEMENT_DIAGNOSTIC_RECOMMENDATION','APPROVAL_REQUIRED',"'owner_id'=>","'due_at'=>","'workflow_code'=>"] as $needle)$assert(str_contains($accept,$needle),'Recommendation → Action missing: '.$needle);

$services=$read('symfony/config/services.yaml');
foreach(['Domains\\Diagnostic\\Bootstrap\\DiagnosticDomainModule','Domains\\Diagnostic\\Application\\Contract\\DiagnosticRuntimeRepositoryInterface','diagnostic.action-outcome.v1'] as $needle)$assert(str_contains($services,$needle),'Symfony wiring missing: '.$needle);

echo "Symfony Sales Diagnostics Wave 5 architecture boundary OK\n";
