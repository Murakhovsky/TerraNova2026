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
if(($manifest['version']??null)!=='0.9.0'||($manifest['schema_version']??null)!=='0.9.0'){
    throw new RuntimeException('Capital Markets module manifest must be V0.9.0.');
}
if(($manifest['enabled_by_default']??true)!==false){
    throw new RuntimeException('Capital Markets Foundation must remain disabled by default.');
}
if(($manifest['contributions']['runtime_module_service']??null)!=='capitalMarketsDomainModule'){
    throw new RuntimeException('Capital Markets Foundation runtime module service is missing.');
}
if(!in_array('capitalMarketsResearchBacktestJobHandler',$manifest['contributions']['job_handler_services']??[],true)){
    throw new RuntimeException('Capital Markets Research backtest queue handler is missing from module contributions.');
}
foreach([
    'app/migrations/20261005_000124_capital_markets_foundation.sql',
    'app/migrations/20261006_000125_capital_markets_market_sources.sql',
    'app/migrations/20261006_000126_capital_markets_market_events.sql',
    'app/migrations/20261006_000127_capital_markets_market_state.sql',
    'app/migrations/20261006_000128_capital_markets_tokenized_equity_vertical_slice.sql',
    'app/migrations/20261006_000129_capital_markets_tokenized_equity_research.sql',
    'app/migrations/20261006_000130_capital_markets_execution_recovery.sql',
    'app/migrations/20261006_000131_capital_markets_kraken_market_data.sql',
    'app/migrations/20261006_000132_capital_markets_crypto_spot_perpetual.sql',
    'app/migrations/20261006_000133_capital_markets_research_lab.sql',
    'app/migrations/20261008_000134_capital_markets_capital_risk.sql',
] as $migrationFile){
    if(!in_array($migrationFile,$manifest['contributions']['migration_files']??[],true)){
        throw new RuntimeException('Capital Markets migration is missing: '.$migrationFile);
    }
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

if(str_contains($migration,'ON UPDATE CASCADE')){
    throw new RuntimeException('Capital Markets canonical IDs are immutable; Foundation foreign keys must not cascade identifier updates.');
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
    '/capital-markets/tokenized-equities',
    '/api/v1/capital-markets/tokenized-equities',
    '/api/v1/capital-markets/tokenized-equities/scan/universe',
    '/api/v1/capital-markets/tokenized-equities/market-replay',
    '/capital-markets/research',
    '/api/v1/capital-markets/research/backtests/run',
    '/api/v1/capital-markets/research/walk-forward',
    '/api/v1/capital-markets/research/agent/run',
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
    'TokenizedEquityVerticalSliceService',
    'TokenizedEquityPaperExecutionService',
    'MysqlTokenizedEquityVerticalSliceRepository',
    'ResearchLabService',
    'ResearchBacktestService',
    'RelativeValueHistoricalReplayService',
    'CapitalMarketsResearchAgentService',
    'ResearchBacktestJobHandler',
    'runtime.capital_markets_research_backtest_job_handler',
    'capitalMarketsResearchBacktestJobHandler',
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

$paperExecution=(string)file_get_contents($domainRoot.'/Application/Service/TokenizedEquityPaperExecutionService.php');
foreach(['getExecutionForOpportunity','recordInvalidated','INSUFFICIENT_LIQUIDITY'] as $needle){
    if(!str_contains($paperExecution,$needle)){
        throw new RuntimeException('Tokenized Equity execution hardening missing: '.$needle);
    }
}
$verticalSlice=(string)file_get_contents($domainRoot.'/Application/Service/TokenizedEquityVerticalSliceService.php');
foreach(['crossVenueObservationIssues','referenceObservationIssues',"'observable'=>\$observable"] as $needle){
    if(!str_contains($verticalSlice,$needle)){
        throw new RuntimeException('Tokenized Equity observability gate missing: '.$needle);
    }
}
$researchEngine=(string)file_get_contents($domainRoot.'/Domain/Research/HypothesisResearchEngine.php');
foreach(['unobservable_scan_count','execution_attempt_count','invalidated_execution_count','completion_rate'] as $needle){
    if(!str_contains($researchEngine,$needle)){
        throw new RuntimeException('Tokenized Equity unbiased research metric missing: '.$needle);
    }
}
$verticalRepository=(string)file_get_contents($domainRoot.'/Infrastructure/Persistence/MySql/MysqlTokenizedEquityVerticalSliceRepository.php');
if(!str_contains($verticalRepository,'LIMIT 1 FOR UPDATE')||!str_contains($verticalRepository,'getExecutionForOpportunity')){
    throw new RuntimeException('Tokenized Equity execution idempotency must serialize by opportunity row.');
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
    if(!str_contains($repositorySource,'$ownsTransaction=!$this->connection->inTransaction()')){
        throw new RuntimeException('Capital Markets repository must detect ownership of the database transaction: '.basename($repositoryFile));
    }
    if(!str_contains($repositorySource,'if($ownsTransaction)$this->connection->commit();')){
        throw new RuntimeException('Capital Markets repository must commit only the transaction it owns: '.basename($repositoryFile));
    }
    if(!str_contains($repositorySource,'if($ownsTransaction&&$this->connection->inTransaction())$this->connection->rollBack();')){
        throw new RuntimeException('Capital Markets repository must rollback only the transaction it owns: '.basename($repositoryFile));
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
foreach([
    'tn_capital_market_instruments','tn_capital_market_relationships','tn_capital_market_venues',
    'tn_capital_market_data_sources','tn_capital_market_raw_events','tn_capital_market_canonical_events',
    'tn_capital_market_states','tn_capital_market_reference_states',
    'tn_capital_market_spread_candidates','tn_capital_market_opportunities','tn_capital_market_risk_assessments',
    'tn_capital_market_paper_executions','tn_capital_market_ledger_transactions','tn_capital_market_paper_portfolios',
    'tn_capital_market_paper_nav_snapshots',
    'tn_capital_market_capital_reservations','tn_capital_market_paper_balances',
    'tn_capital_market_paper_balance_reservations',
    'tn_capital_market_research_hypotheses','tn_capital_market_research_datasets',
    'tn_capital_market_strategy_versions','tn_capital_market_research_experiments',
    'tn_capital_market_research_results','tn_capital_market_strategy_promotion_decisions',
    'tn_capital_market_backtest_runs','tn_capital_market_oos_runs','tn_capital_market_paper_runs',
    'tn_capital_market_strategy_scorecards','tn_capital_market_rejected_hypotheses',
    'tn_capital_market_research_knowledge'
] as $table){
    if(!str_contains($ownership,$table))throw new RuntimeException('Capital Markets table ownership missing: '.$table);
}

$readme=(string)file_get_contents($domainRoot.'/README.md');
foreach([
    'does **not** create another Money class',
    'disabled by default',
    'provider-specific adapters',
    'no fake prices',
    'Live Trading',
] as $needle){
    if(!str_contains($readme,$needle))throw new RuntimeException('Capital Markets foundation boundary is undocumented: '.$needle);
}

$domainIterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($domainRoot));
foreach($domainIterator as $candidate){
    if(!$candidate->isFile())continue;
    if(in_array($candidate->getFilename(),['LiveOrder.php','LiveTrade.php','Backtest.php'],true)){
        throw new RuntimeException('Out-of-scope V0.4 live/backtest entity exists: '.$candidate->getFilename());
    }
}

echo sprintf("Capital Markets boundaries passed: %d pure domain files.\n",count($files));
