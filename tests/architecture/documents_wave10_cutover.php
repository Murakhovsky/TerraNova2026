<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$assert(!is_dir($root.'/app/Domains/Documents'),'Documents must remain a Platform capability, not become a duplicate business Domain.');

$migration='app/migrations/20260919_000063_documents_wave10_cutover.sql';
$migrationSql=$read($migration);
foreach([
    'cos_documents','cos_document_files','cos_document_versions','cos_document_relations',
    'cos_document_templates','cos_document_signatures','cos_document_operation_receipts',
] as $table){
    $assert(str_contains($migrationSql,$table),'Wave 10 migration missing table: '.$table);
}

$ownership=$read('app/Infrastructure/Platform/Persistence/TableOwnership.php');
foreach([
    'cos_documents','cos_document_files','cos_document_versions','cos_document_relations',
    'cos_document_templates','cos_document_signatures','cos_document_operation_receipts',
] as $table){
    $assert(str_contains($ownership,"'".$table."'"),'Platform table ownership missing: '.$table);
}

$service=$read('app/Platform/Documents/Service/DocumentsRuntimeService.php');
foreach([
    'DocumentAttachmentPort','DocumentsRepositoryInterface','DocumentMutationReceiptInterface',
    'FileStorageInterface','TransactionManagerInterface','EventBus','AuditRepositoryInterface',
    'receipts->claim','transactions->transactional','storage->put','storage->delete',
    'DocumentsEventType::UPLOADED','DocumentsEventType::ATTACHED','DocumentsEventType::VERSION_CREATED',
    'DocumentsEventType::GENERATED','DocumentsEventType::SIGNATURE_REQUESTED',
    'DocumentsEventType::SIGNED','DocumentsEventType::ARCHIVED',
    'idempotency_key_hash','MAX_CONTENT_BYTES','Archived Document cannot be signed','lockDocumentStatus',
] as $needle){
    $assert(str_contains($service,$needle),'Documents runtime missing: '.$needle);
}
foreach(['PDO','Symfony\\','Infrastructure\\','Domains\\'] as $forbidden){
    $assert(!str_contains($service,$forbidden),'Documents Platform runtime crossed its architecture boundary: '.$forbidden);
}

$repository=$read('app/Infrastructure/Platform/Persistence/MySql/Documents/MysqlDocumentsRepository.php');
foreach([
    'organization_id=:organization_id','FOR UPDATE','public function lockDocumentStatus','INSERT IGNORE INTO cos_document_relations',
    'INSERT IGNORE INTO cos_document_signatures',"status=\\'signed\\'","status=\\'archived\\'",
] as $needle){
    $assert(str_contains($repository,$needle),'Documents persistence hardening missing: '.$needle);
}
$assert(substr_count($repository,'organization_id')>=30,'Documents persistence must remain tenant-scoped.');

$receipt=$read('app/Infrastructure/Platform/Persistence/MySql/Documents/MysqlDocumentMutationReceipt.php');
foreach(['INSERT IGNORE INTO cos_document_operation_receipts','payload_fingerprint','hash_equals'] as $needle){
    $assert(str_contains($receipt,$needle),'Documents idempotency receipt missing: '.$needle);
}

foreach([
    'UploadDocumentCommand.php',
    'AttachDocumentCommand.php',
    'CreateDocumentVersionCommand.php',
    'GenerateDocumentFromTemplateCommand.php',
    'RequestDocumentSignatureCommand.php',
    'SignDocumentCommand.php',
    'ArchiveDocumentCommand.php',
] as $command){
    $assert(is_file($root.'/symfony/src/Application/Documents/Command/'.$command),'Missing explicit Documents command: '.$command);
}

$controller=$read('symfony/src/Http/Api/V1/Controller/DocumentsController.php');
foreach([
    'CommandBusInterface','QueryBusInterface','TenantContextProviderInterface','TenantPermissions::ACCESS',
    'TenantPermissions::MANAGE','LegacySessionCsrfValidator','X-Idempotency-Key',
    'UploadDocumentCommand','AttachDocumentCommand','CreateDocumentVersionCommand',
    'GenerateDocumentFromTemplateCommand','RequestDocumentSignatureCommand',
    'SignDocumentCommand','ArchiveDocumentCommand',
] as $needle){
    $assert(str_contains($controller,$needle),'Documents API boundary missing: '.$needle);
}
$assert(!str_contains($controller,'ActiveModuleResolver'),'Platform Documents must not be gated as an installable Domain module.');
$assert(!str_contains($controller,'PDO'),'Documents controller must not own persistence.');

$routes=$read('symfony/config/routes.yaml');
foreach([
    '/api/v1/documents',
    '/api/v1/documents/{id}/attachments',
    '/api/v1/documents/{id}/versions',
    '/api/v1/document-templates/{id}/documents',
    '/api/v1/documents/{id}/signature-requests',
    '/api/v1/document-signatures/{id}/sign',
    '/api/v1/documents/{id}/archive',
] as $route){
    $assert(str_contains($routes,$route),'Wave 10 route missing: '.$route);
}

$services=$read('symfony/config/services.yaml');
foreach([
    'DocumentsRepositoryInterface','DocumentMutationReceiptInterface','DocumentAttachmentPort',
    'DocumentsRuntimeService','MysqlDocumentsRepository','MysqlDocumentMutationReceipt',
] as $needle){
    $assert(str_contains($services,$needle),'Wave 10 Symfony DI missing: '.$needle);
}

$attachmentPort=$read('app/Platform/Documents/Contract/DocumentAttachmentPort.php');
$assert(str_contains($attachmentPort,'extends DocumentsCapabilityBoundary'),'Cross-domain document attachment must use the stable Platform capability boundary.');

echo "Platform Documents Wave 10 architecture: OK\n";
