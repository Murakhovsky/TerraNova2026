<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$manifest=require $root.'/app/Domains/Service/module.php';
$assert(($manifest['version']??null)==='0.2.0','Service Wave 11 manifest version must be 0.2.0.');
$assert(($manifest['schema_version']??null)==='0.2.0','Service Wave 11 schema version must be 0.2.0.');
$assert(($manifest['enabled_by_default']??false)===true,'Service Wave 11 must be enabled by default.');
$assert(($manifest['contributions']['runtime_module_service']??null)==='serviceDomainModule','Service runtime module contribution is missing.');
$assert(in_array('app/migrations/20260919_000064_service_wave11_cutover.sql',$manifest['contributions']['migration_files']??[],true),'Service migration contribution is missing.');
foreach(['service.request','service.ticket','service.assignment','service.sla','service.escalation','service.resolution','service.api.v1'] as $capability){
    $assert(in_array($capability,$manifest['contributions']['capabilities']??[],true),'Service capability missing: '.$capability);
}

$migration=$read('app/migrations/20260919_000064_service_wave11_cutover.sql');
foreach([
    'tn_service_cases','tn_service_requests','tn_service_tickets','tn_service_assignments',
    'tn_service_slas','tn_service_escalations','tn_service_resolutions','tn_service_operation_receipts',
    "module_id='service'","installed_version='0.2.0'","schema_version='0.2.0'","status='INSTALLED'",
] as $needle){
    $assert(str_contains($migration,$needle),'Service migration missing: '.$needle);
}

$ownership=$read('app/Infrastructure/Platform/Persistence/TableOwnership.php');
foreach([
    'tn_service_cases','tn_service_requests','tn_service_tickets','tn_service_assignments',
    'tn_service_slas','tn_service_escalations','tn_service_resolutions','tn_service_operation_receipts',
] as $table){
    $assert(str_contains($ownership,"'".$table."'"),'Service table ownership missing: '.$table);
}

$repository=$read('app/Domains/Service/Infrastructure/Persistence/MySql/MysqlServiceRepository.php');
foreach([
    'organization_id=:organization_id','FOR UPDATE','public function lockRequest','public function lockTicket','tn_service_assignments','tn_service_slas',
    'tn_service_escalations','tn_service_resolutions',"status=\\'closed\\'","status=\\'resolved\\'",
] as $needle){
    $assert(str_contains($repository,$needle),'Service repository hardening missing: '.$needle);
}
$assert(substr_count($repository,'organization_id')>=45,'Service persistence must remain tenant-scoped.');

$receipt=$read('app/Domains/Service/Infrastructure/Persistence/MySql/MysqlServiceMutationReceipt.php');
foreach(['INSERT IGNORE INTO tn_service_operation_receipts','payload_fingerprint','hash_equals'] as $needle){
    $assert(str_contains($receipt,$needle),'Service idempotency receipt missing: '.$needle);
}

$workflow=$read('app/Domains/Service/Application/Service/ServiceWorkflowService.php');
foreach([
    'ServiceApplicationBoundary','ServiceRepositoryInterface','ServiceMutationReceiptInterface',
    'TransactionManagerInterface','EventBus','AuditRepositoryInterface','receipts->claim','transactions->transactional',
    'lockRequest(','lockTicket(','ServiceTicketLifecycle::assignmentStatus','ServiceTicketLifecycle::assertMutable',
    'ServiceTicketLifecycle::assertResolvable','ServiceTicketLifecycle::assertClosable','integerInput(','is_int(',
    'ServiceEventType::REQUEST_CREATED','ServiceEventType::TICKET_CREATED','ServiceEventType::TICKET_ASSIGNED',
    'ServiceEventType::SLA_SET','ServiceEventType::TICKET_ESCALATED','ServiceEventType::TICKET_RESOLVED',
    'ServiceEventType::TICKET_CLOSED','idempotency_key_hash',
] as $needle){
    $assert(str_contains($workflow,$needle),'Service workflow missing: '.$needle);
}
foreach(['PDO','Symfony\\','Phalcon\\','Infrastructure\\','Platform\\'] as $forbidden){
    $assert(!str_contains($workflow,$forbidden),'Service application crossed its boundary: '.$forbidden);
}

foreach([
    'CreateServiceRequestCommand.php','CreateServiceTicketCommand.php','AssignServiceTicketCommand.php',
    'SetServiceSlaCommand.php','EscalateServiceTicketCommand.php','ResolveServiceTicketCommand.php',
    'CloseServiceTicketCommand.php',
] as $command){
    $assert(is_file($root.'/symfony/src/Application/Service/Command/'.$command),'Missing explicit Service command: '.$command);
}

$controller=$read('symfony/src/Http/Api/V1/Controller/ServiceController.php');
foreach([
    'CommandBusInterface','QueryBusInterface','ActiveModuleResolver','TenantPermissions::ACCESS',
    'TenantPermissions::MANAGE','SessionCsrfValidator','X-Idempotency-Key',
    "'service'","CreateServiceRequestCommand","CreateServiceTicketCommand","AssignServiceTicketCommand",
    "SetServiceSlaCommand","EscalateServiceTicketCommand","ResolveServiceTicketCommand","CloseServiceTicketCommand",
] as $needle){
    $assert(str_contains($controller,$needle),'Service API boundary missing: '.$needle);
}
$assert(!str_contains($controller,'PDO'),'Service controller must not own persistence.');

$routes=$read('symfony/config/routes.yaml');
foreach([
    '/api/v1/service/requests','/api/v1/service/requests/{id}/tickets','/api/v1/service/tickets/{id}',
    '/api/v1/service/tickets/{id}/assignments','/api/v1/service/tickets/{id}/sla',
    '/api/v1/service/tickets/{id}/escalations','/api/v1/service/tickets/{id}/resolution',
    '/api/v1/service/tickets/{id}/close',
] as $route){
    $assert(str_contains($routes,$route),'Wave 11 route missing: '.$route);
}

$services=$read('symfony/config/services.yaml');
foreach([
    'ServiceRepositoryInterface','ServiceMutationReceiptInterface','ServiceApplicationBoundary',
    'ServiceWorkflowService','MysqlServiceRepository','MysqlServiceMutationReceipt',
    'ServiceDomainModule',
] as $needle){
    $assert(str_contains($services,$needle),'Wave 11 Symfony DI missing: '.$needle);
}
$assert(str_contains($services,'Domains\\Service\\Bootstrap\\ServiceDomainModule:'),'Service Domain module is not wired in Symfony composition.');
$assert(!is_file($root.'/app/Bootstrap/ServiceServices.php'),'Retired Service bootstrap must remain deleted.');

$process=$read('resources/processes/service-request-to-close.json');
foreach([
    '"id": "service.request-to-close"','"domain": "service"','"service.request"','"service.ticket"',
    '"service.assignment"','"service.sla"','"service.escalation"','"service.resolution"',
] as $needle){
    $assert(str_contains($process,$needle),'Service process definition missing: '.$needle);
}
$exemptions=$read('docs/.vitepress/process-coverage-exemptions.json');
$assert(!str_contains($exemptions,'"domain": "service"'),'Service runtime must not retain a process coverage exemption.');

echo "Service Wave 11 architecture: OK\n";
