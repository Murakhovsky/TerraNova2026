<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$property=require $root.'/app/Domains/Property/module.php';
$assert(($property['version']??null)==='0.12.0','Wave 9 must not bump Property lifecycle version without an explicit tenant upgrade.');
$assert(($property['schema_version']??null)==='0.12.0','Wave 9 keeps the current Property schema version.');
foreach(['property.api.v1','property.business.cutover'] as $capability){
    $assert(in_array($capability,$property['contributions']['capabilities']??[],true),'Missing Property Wave 9 capability: '.$capability);
}

$realEstate=require $root.'/app/Domains/RealEstate/module.php';
$assert(($realEstate['version']??null)==='0.2.0','Wave 9 requires RealEstate V0.2.0.');
$assert(($realEstate['schema_version']??null)==='0.2.0','RealEstate schema must advertise V0.2.0.');
$assert(($realEstate['enabled_by_default']??false)===true,'RealEstate Wave 9 runtime must be active by default.');
foreach(['property','sales'] as $dependency){
    $assert(in_array($dependency,$realEstate['dependencies']??[],true),'RealEstate missing dependency: '.$dependency);
}
$assert(($realEstate['contributions']['runtime_module_service']??null)==='realEstateDomainModule','RealEstate runtime module service is missing.');
$migration='app/migrations/20260918_000062_real_estate_wave9_cutover.sql';
$assert(in_array($migration,$realEstate['contributions']['migration_files']??[],true),'RealEstate Wave 9 migration is not declared.');
$migrationSql=$read($migration);
foreach(['tn_real_estate_cases','tn_real_estate_offers','tn_real_estate_showings','tn_real_estate_operation_receipts','organization_id','uq_real_estate_match'] as $needle){
    $assert(str_contains($migrationSql,$needle),'RealEstate migration missing: '.$needle);
}

$realEstateModule=$read('app/Domains/RealEstate/Bootstrap/RealEstateDomainModule.php');
$realEstateRuleContext=$read('app/Domains/RealEstate/Rule/RealEstateRuleContextProvider.php');
$assert(str_contains($realEstateModule,'ruleContextProvider()'),'RealEstate EventOwning module must expose ruleContextProvider().');
foreach(['RealEstateEventType::values()','brokerage_case_id','event_type'] as $needle){
    $assert(str_contains($realEstateRuleContext,$needle),'RealEstate rule context provider missing: '.$needle);
}

$workflow=$read('app/Domains/RealEstate/Application/Service/RealEstateWorkflowService.php');
foreach(['SalesOpportunityReferenceInterface','PropertyBrokerageReferencePort','PropertyInventoryCommandInterface','RealEstateMutationReceiptInterface','AuditRepositoryInterface','receipts->claim','createCase(','createOffer(','createShowing(','events->publish','appendAudit'] as $needle){
    $assert(str_contains($workflow,$needle),'RealEstate workflow missing boundary/runtime behavior: '.$needle);
}
$assert(!preg_match('/\btn_(?:property|sales|crm)_/i',$workflow),'RealEstate application service must not read another domain tables directly.');
$assert(!str_contains($workflow,'PDO'),'RealEstate application service must not own persistence.');

$realEstateRepository=$read('app/Domains/RealEstate/Infrastructure/Persistence/MySql/MysqlRealEstateRepository.php');
foreach(['INSERT IGNORE INTO tn_real_estate_cases','INSERT IGNORE INTO tn_real_estate_offers','INSERT IGNORE INTO tn_real_estate_showings'] as $needle){
    $assert(str_contains($realEstateRepository,$needle),'RealEstate idempotent insert missing: '.$needle);
}
$assert(substr_count($realEstateRepository,'organization_id')>=12,'RealEstate persistence must be tenant-scoped.');

$runtime=$read('app/Domains/Property/Application/Service/PropertyCanonicalRuntimeService.php');
$canonicalRepository=$read('app/Domains/Property/Infrastructure/Persistence/MySql/MysqlPropertyCanonicalRuntimeRepository.php');
$assert(str_contains($runtime,'reserveInventory')&&str_contains($runtime,'transactions->transactional'),'Property reservation must remain transactional.');
$assert(str_contains($canonicalRepository,'FOR UPDATE'),'Property reservation path must serialize inventory mutation with a row lock.');

$symfonyServices=$read('symfony/config/services.yaml');
foreach([
    'PropertyCanonicalRuntimeRepositoryInterface',
    'PropertyReferencePort',
    'PropertyBrokerageReferencePort',
    'PropertyInventoryCommandInterface',
    'PropertyMutationReceiptInterface',
    'RealEstateRepositoryInterface',
    'RealEstateMutationReceiptInterface',
    'SalesOpportunityReferenceInterface',
    'RealEstateWorkflowService',
    'Domains\\Property\\Bootstrap\\PropertyDomainModule',
    'Domains\\RealEstate\\Bootstrap\\RealEstateDomainModule',
] as $needle){
    $assert(str_contains($symfonyServices,$needle),'Symfony Wave 9 DI missing: '.$needle);
}
$ownership=$read('app/Infrastructure/Platform/Persistence/TableOwnership.php');
foreach(['tn_real_estate_cases','tn_real_estate_offers','tn_real_estate_showings','tn_real_estate_operation_receipts'] as $table){
    $assert(str_contains($ownership,"'".$table."'"),'RealEstate table ownership missing: '.$table);
}

$kernel=$read('app/config/services_kernel.php');
$assert(str_contains($kernel,'RealEstateServices.php'),'Common composition root must load RealEstate runtime.');

$routes=$read('symfony/config/routes.yaml');
foreach([
    '/api/v1/properties',
    '/api/v1/properties/{id}/presentation',
    '/api/v1/property-inventory/{id}/reservations',
    '/api/v1/sales/opportunities/{id}/property-matches',
    '/api/v1/real-estate/cases/{id}/offers',
    '/api/v1/real-estate/cases/{id}/viewings',
    '/api/v1/real-estate/cases/{id}/reservation',
] as $route){
    $assert(str_contains($routes,$route),'Wave 9 Symfony route missing: '.$route);
}

$propertyWrites=$read('symfony/src/Application/Property/Service/PropertyWriteService.php');
$assert(str_contains($propertyWrites,'AuditRepositoryInterface')&&str_contains($propertyWrites,'transactions->transactional'),'Property mutations must audit inside the shared transaction.');
$assert(str_contains($propertyWrites,'PropertyMutationReceiptInterface')&&str_contains($propertyWrites,'receipts->claim'),'Property writes must use atomic mutation receipts.');

$receipt=$read('symfony/src/Infrastructure/Persistence/Property/PdoPropertyMutationReceipt.php');
foreach(['INSERT IGNORE INTO cos_external_references','cos_reference','external_id'] as $needle){
    $assert(str_contains($receipt,$needle),'Property idempotency receipt missing atomic persistence behavior: '.$needle);
}

foreach([
    'CreatePropertyCommand.php',
    'UpdatePropertyCommand.php',
    'CreateInventoryCommand.php',
    'ChangeInventoryStatusCommand.php',
    'ReserveInventoryCommand.php',
] as $command){
    $assert(is_file($root.'/symfony/src/Application/Property/Command/'.$command),'Missing explicit Property business command: '.$command);
}
foreach([
    'MatchPropertyCommand.php',
    'CreatePropertyOfferCommand.php',
    'SchedulePropertyViewingCommand.php',
    'ReserveMatchedPropertyCommand.php',
] as $command){
    $assert(is_file($root.'/symfony/src/Application/RealEstate/Command/'.$command),'Missing explicit RealEstate business command: '.$command);
}
$assert(!is_file($root.'/symfony/src/Application/Property/Command/PropertyMutationCommand.php'),'Generic Property mutation command must not exist after Wave 9 cutover.');
$assert(!is_file($root.'/symfony/src/Application/RealEstate/Command/RealEstateMutationCommand.php'),'Generic RealEstate mutation command must not exist after Wave 9 cutover.');

$propertyController=$read('symfony/src/Http/Api/V1/Controller/PropertyController.php');
$realEstateController=$read('symfony/src/Http/Api/V1/Controller/RealEstateController.php');
foreach([$propertyController,$realEstateController] as $controller){
    $assert(str_contains($controller,'CommandBusInterface'),'Wave 9 write controller must dispatch commands.');
    $assert(str_contains($controller,'QueryBusInterface'),'Wave 9 controller must use query bus for reads.');
    $assert(str_contains($controller,'LegacySessionCsrfValidator'),'Wave 9 browser writes must enforce CSRF.');
    $assert(str_contains($controller,'X-Idempotency-Key'),'Wave 9 consequential writes must expose idempotency boundary.');
    $assert(str_contains($controller,'TenantContextProviderInterface'),'Wave 9 API must derive tenant from authenticated context.');
    $assert(str_contains($controller,'ActiveModuleResolver'),'Wave 9 API must enforce module activation.');
    $assert(str_contains($controller,'TenantPermissions::MANAGE'),'Wave 9 mutations must require tenant manage permission.');
}
$assert(!str_contains($propertyController,'PropertyMutationCommand'),'Property controller must dispatch explicit business commands.');
$assert(!str_contains($realEstateController,'RealEstateMutationCommand'),'RealEstate controller must dispatch explicit business commands.');
foreach(['CreatePropertyCommand','UpdatePropertyCommand','CreateInventoryCommand','ChangeInventoryStatusCommand','ReserveInventoryCommand'] as $command){
    $assert(str_contains($propertyController,$command),'Property controller missing explicit command: '.$command);
}
foreach(['MatchPropertyCommand','CreatePropertyOfferCommand','SchedulePropertyViewingCommand','ReserveMatchedPropertyCommand'] as $command){
    $assert(str_contains($realEstateController,$command),'RealEstate controller missing explicit command: '.$command);
}

echo "Property / RealEstate Wave 9 architecture: OK\n";
