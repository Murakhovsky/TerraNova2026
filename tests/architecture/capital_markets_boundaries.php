<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';

$domainRoot=$root.'/app/Domains/CapitalMarkets';
$pureDomainRoot=$domainRoot.'/Domain';

if(!is_dir($domainRoot)||!is_dir($domainRoot.'/Application')||!is_dir($domainRoot.'/Infrastructure')||!is_dir($pureDomainRoot)){
    throw new RuntimeException('Capital Markets bounded context layout is incomplete.');
}

$files=[];
$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pureDomainRoot));
foreach($iterator as $file){
    if($file->isFile()&&$file->getExtension()==='php')$files[]=$file->getPathname();
}
if($files===[])throw new RuntimeException('Capital Markets pure Domain contains no PHP model.');

$forbidden=['Symfony\\','Phalcon\\','Infrastructure\\','Platform\\','PDO','curl_','Guzzle','Binance','Kraken','Bybit'];
foreach($files as $file){
    $source=(string)file_get_contents($file);
    foreach($forbidden as $needle){
        if(str_contains($source,$needle)){
            throw new RuntimeException(sprintf(
                'Capital Markets pure Domain depends on forbidden runtime/integration symbol %s in %s.',
                $needle,substr($file,strlen($root)+1)
            ));
        }
    }
    if(preg_match('/\bfloat\b|\(float\)|floatval\s*\(/i',$source)===1){
        throw new RuntimeException('Capital Markets pure Domain contains forbidden floating-point semantics: '.substr($file,strlen($root)+1));
    }
}

if(is_file($pureDomainRoot.'/Value/Money.php')){
    throw new RuntimeException('Capital Markets must reuse Kernel\\Shared\\Domain\\Money instead of duplicating Money.');
}

foreach([
    $domainRoot.'/Domain/Contract/InstrumentRepository.php',
    $domainRoot.'/Domain/Contract/RelationshipRepository.php',
    $domainRoot.'/Domain/Contract/VenueRepository.php',
    $domainRoot.'/Domain/Contract/VenueAdapterInterface.php',
    $domainRoot.'/Application/Contract/CapitalMarketsFoundationBoundary.php',
    $domainRoot.'/Infrastructure/Persistence/MySql/MysqlInstrumentRepository.php',
    $domainRoot.'/Infrastructure/Persistence/MySql/MysqlRelationshipRepository.php',
    $domainRoot.'/Infrastructure/Persistence/MySql/MysqlVenueRepository.php',
] as $required){
    if(!is_file($required))throw new RuntimeException('Capital Markets Foundation file missing: '.substr($required,strlen($root)+1));
}

$manifest=require $domainRoot.'/module.php';
if(($manifest['version']??null)!=='0.2.0'||($manifest['schema_version']??null)!=='0.2.0'){
    throw new RuntimeException('Capital Markets Foundation manifest must be V0.2.0.');
}
if(($manifest['enabled_by_default']??true)!==false){
    throw new RuntimeException('Capital Markets Foundation must remain disabled by default.');
}
if(($manifest['contributions']['runtime_module_service']??null)!=='capitalMarketsDomainModule'){
    throw new RuntimeException('Capital Markets Foundation runtime module service is missing.');
}
if(!in_array('app/migrations/20261005_000124_capital_markets_foundation.sql',$manifest['contributions']['migration_files']??[],true)){
    throw new RuntimeException('Capital Markets Foundation migration is missing.');
}
foreach(['web.navigation','web.search','web.commands','web.workspace'] as $extension){
    if(!in_array('capitalMarketsNavigationContributor',$manifest['contributions']['extension_services'][$extension]??[],true)){
        throw new RuntimeException('Capital Markets web extension missing: '.$extension);
    }
}

$migration=(string)file_get_contents($root.'/app/migrations/20261005_000124_capital_markets_foundation.sql');
foreach([
    'tn_capital_market_instruments',
    'tn_capital_market_instrument_identifiers',
    'tn_capital_market_relationships',
    'tn_capital_market_pairs',
    'tn_capital_market_venues',
    'tn_capital_market_venue_capabilities',
    'tn_capital_market_venue_instruments',
    'capital_market_user_capabilities',
    'organization_id',
    'uq_cm_identifier',
    'chk_cm_relationship_distinct',
    'chk_cm_relationship_window',
    'capital_markets.paper_trading.enabled',
    'capital_markets.live_trading.enabled',
    'capital_markets.auto_execution.enabled',
] as $needle){
    if(!str_contains($migration,$needle))throw new RuntimeException('Capital Markets migration contract missing: '.$needle);
}
foreach([
    "('capital_markets.paper_trading.enabled','Future Capital Markets paper trading runtime',0,0",
    "('capital_markets.live_trading.enabled','Future Capital Markets live trading runtime',0,0",
    "('capital_markets.auto_execution.enabled','Future Capital Markets autonomous execution runtime',0,0",
] as $disabledFlag){
    if(!str_contains($migration,$disabledFlag))throw new RuntimeException('Future execution flag is not safely disabled: '.$disabledFlag);
}
if(str_contains($migration,'fk_cm_pair_relationship')&&str_contains($migration,'ON DELETE SET NULL')){
    throw new RuntimeException('Composite tenant relationship FK must not null organization_id on delete.');
}

$routes=(string)file_get_contents($root.'/symfony/config/routes.yaml');
foreach([
    '/capital-markets',
    '/capital-markets/instruments',
    '/capital-markets/relationships',
    '/capital-markets/venues',
    '/api/v1/capital-markets/instruments',
    '/api/v1/capital-markets/relationships',
    '/api/v1/capital-markets/venues',
] as $route){
    if(!str_contains($routes,$route))throw new RuntimeException('Capital Markets route missing: '.$route);
}

$services=(string)file_get_contents($root.'/symfony/config/services.yaml');
foreach([
    'CapitalMarketsFoundationBoundary',
    'MysqlInstrumentRepository',
    'MysqlRelationshipRepository',
    'MysqlVenueRepository',
    'CapitalMarketsAuditTrail',
    'CapitalMarketsFeatureGate',
    'KernelCapitalMarketsEventPublisher',
] as $service){
    if(!str_contains($services,$service))throw new RuntimeException('Capital Markets service wiring missing: '.$service);
}

foreach([
    $domainRoot.'/Infrastructure/Persistence/MySql/MysqlInstrumentRepository.php',
    $domainRoot.'/Infrastructure/Persistence/MySql/MysqlRelationshipRepository.php',
    $domainRoot.'/Infrastructure/Persistence/MySql/MysqlVenueRepository.php',
] as $repositoryFile){
    $repositorySource=(string)file_get_contents($repositoryFile);
    if(str_contains($repositorySource,'ON DUPLICATE KEY UPDATE')){
        throw new RuntimeException('Capital Markets registry repository must not mask aggregate identity conflicts with unsafe upsert: '.basename($repositoryFile));
    }
}

$foundationService=(string)file_get_contents($domainRoot.'/Application/Service/CapitalMarketsFoundationService.php');
foreach(['TransactionManagerInterface','transactional(fn():array=>$this->createInstrument($command))','transactional(fn():array=>$this->createRelationship($command))','transactional(fn():array=>$this->createVenue($command))'] as $needle){
    if(!str_contains($foundationService,$needle)){
        throw new RuntimeException('Capital Markets application transaction boundary missing: '.$needle);
    }
}
foreach([
    $domainRoot.'/Infrastructure/Persistence/MySql/MysqlInstrumentRepository.php',
    $domainRoot.'/Infrastructure/Persistence/MySql/MysqlVenueRepository.php',
] as $repositoryFile){
    $repositorySource=(string)file_get_contents($repositoryFile);
    foreach(['$ownsTransaction=!$this->connection->inTransaction()','if($ownsTransaction)$this->connection->commit()'] as $needle){
        if(!str_contains($repositorySource,$needle)){
            throw new RuntimeException('Capital Markets repository must cooperate with outer transaction: '.basename($repositoryFile).' -> '.$needle);
        }
    }
}

$eventPublisher=(string)file_get_contents($domainRoot.'/Infrastructure/Event/KernelCapitalMarketsEventPublisher.php');
foreach(['Kernel\\Event\\EventBus','Kernel\\Event\\DomainEvent','Kernel\\Event\\EventMetadata'] as $needle){
    if(!str_contains($eventPublisher,$needle)){
        throw new RuntimeException('Capital Markets event adapter must use canonical Kernel event runtime: '.$needle);
    }
}
if(str_contains($eventPublisher,'IntegrationOutboxInterface')){
    throw new RuntimeException('Capital Markets domain events must not use the external integration outbox.');
}

$ownership=(string)file_get_contents($root.'/app/Infrastructure/Platform/Persistence/TableOwnership.php');
foreach(['tn_capital_market_instruments','tn_capital_market_relationships','tn_capital_market_venues'] as $table){
    if(!str_contains($ownership,$table))throw new RuntimeException('Capital Markets table ownership missing: '.$table);
}

$readme=(string)file_get_contents($domainRoot.'/README.md');
foreach([
    'does **not** fetch market data',
    'does **not** create another Money class',
    'disabled by default',
    'no exchange SDK',
    'no fake prices',
] as $needle){
    if(!str_contains($readme,$needle))throw new RuntimeException('Capital Markets foundation boundary is undocumented: '.$needle);
}

$domainIterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($domainRoot));
foreach($domainIterator as $candidate){
    if(!$candidate->isFile())continue;
    if(in_array($candidate->getFilename(),['Order.php','Trade.php','Position.php','Portfolio.php','Backtest.php'],true)){
        throw new RuntimeException('Out-of-scope execution entity exists: '.$candidate->getFilename());
    }
}

echo sprintf("Capital Markets boundaries passed: %d pure domain files.\n",count($files));
