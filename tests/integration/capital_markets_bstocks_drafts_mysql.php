<?php
declare(strict_types=1);

require (getenv('COS_TEST_AUTOLOAD') ?: dirname(__DIR__,2).'/vendor/autoload.php');

use Domains\CapitalMarkets\Application\Audit\CapitalMarketsAuditTrail;
use Domains\CapitalMarkets\Application\Command\CreateInstrument;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsEventPublisherInterface;
use Domains\CapitalMarkets\Application\Service\BStocksCanonicalDraftProjection;
use Domains\CapitalMarkets\Application\Service\BStocksCanonicalDraftService;
use Domains\CapitalMarkets\Application\Service\BStocksSeedFileReader;
use Domains\CapitalMarkets\Application\Service\CapitalMarketsFoundationService;
use Domains\CapitalMarkets\Domain\Event\AbstractCapitalMarketsEvent;
use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\BStocksSeedImportRepository;
use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\MysqlInstrumentRepository;
use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\MysqlRelationshipRepository;
use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\MysqlVenueRepository;
use Infrastructure\Platform\Persistence\MySql\Transaction\TransactionManager;
use Platform\Audit\Contract\AuditSinkInterface;
use Platform\Audit\Model\ActivityRecord;
use Platform\Audit\Service\AuditRecorder;

$assert=static function(bool $success,string $reason):void {
    if (!$success) throw new RuntimeException($reason);
};
$pdo=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    getenv('DB_HOST') ?: '127.0.0.1',(int)(getenv('DB_PORT') ?: 3306),
    getenv('DB_DATABASE') ?: 'cos'),
    getenv('DB_USERNAME') ?: 'cos',getenv('DB_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$seed=(new BStocksSeedFileReader())->read(
    getenv('COS_SEED_TEST_CSV') ?: dirname(__DIR__,2).'/resources/capital-markets/universes/binance-bstocks-2026-09-27.csv'
);
$org='cm-draft-ci-'.bin2hex(random_bytes(5));
$conflictOrg=$org.'-clash';
$audit=new class implements AuditSinkInterface {
    public int $count=0;
    public function append(ActivityRecord $record):void { ++$this->count; }
};
$events=new class implements CapitalMarketsEventPublisherInterface {
    public int $count=0;
    public function publish(AbstractCapitalMarketsEvent $event,?string $correlationId=null,?int $actorId=null):void {
        ++$this->count;
    }
};
$instruments=new MysqlInstrumentRepository($pdo);
$foundation=new CapitalMarketsFoundationService(
    $instruments,new MysqlRelationshipRepository($pdo),new MysqlVenueRepository($pdo),
    new CapitalMarketsAuditTrail(new AuditRecorder($audit)),$events,new TransactionManager($pdo),
);
$seeds=new BStocksSeedImportRepository($pdo);
$drafts=new BStocksCanonicalDraftService($seeds,new BStocksCanonicalDraftProjection(),
    $instruments,$foundation);
$count=static function(string $table,string $org)use($pdo):int {
    $q=$pdo->prepare('SELECT COUNT(*) FROM '.$table.' WHERE organization_id=:org');
    $q->execute(['org'=>$org]);
    return (int)$q->fetchColumn();
};
try {
    $assert($seeds->apply($org,1,$seed)['new']===80,'Seed evidence failed to import.');
    $assert($drafts->plan($org)['create']===80,'80 canonical drafts should be available.');
    $assert($count('tn_capital_market_instruments',$org)===0,'Draft preflight wrote canonical instruments.');
    $result=$drafts->apply($org,1,100);
    $assert($result['created']===80 && $result['status']==='DRAFT_ONLY','Draft apply incomplete.');
    $assert($count('tn_capital_market_instruments',$org)===80,'Canonical registry did not persist 80 drafts.');
    $q=$pdo->prepare('SELECT COUNT(*) FROM tn_capital_market_instruments
        WHERE organization_id=:org AND status=:status AND family=:family');
    $q->execute(['org'=>$org,'status'=>'DRAFT','family'=>'tokenized_security']);
    $assert((int)$q->fetchColumn()===80,'Seed drafts gained active status or wrong family.');
    $assert($count('tn_capital_market_relationships',$org)===0
        && $count('tn_capital_market_venue_instruments',$org)===0
        && $count('tn_capital_market_subscriptions',$org)===0,
        'Import must not publish economic links, active venue instruments or subscriptions.');
    $repeat=$drafts->apply($org,1,100);
    $assert($repeat['created']===0 && $drafts->plan($org)['existing']===80,
        'Canonical draft replay must be idempotent.');
    $assert($audit->count===80 && $events->count===80,
        'Actual Foundation boundary must audit and publish each draft exactly once.');
    $q=$pdo->prepare('SELECT COUNT(*) FROM tn_capital_market_instruments
        WHERE organization_id=:org AND symbol=:symbol');
    $q->execute(['org'=>$org,'symbol'=>'AAPL']);
    $assert((int)$q->fetchColumn()===0,'Underlying AAPL was incorrectly created from AAPLB.');
    $assert($seeds->apply($conflictOrg,1,$seed)['new']===80,'Conflict tenant seed import failed.');
    $foundation->createInstrument(new CreateInstrument($conflictOrg,1,'collision-fixture',[
        'id'=>'external-stock-symbol-collision','symbol'=>'AAPLB','canonical_symbol'=>'AAPLB',
        'name'=>'Unrelated local instrument','family'=>'crypto_asset','status'=>'DRAFT',
    ]));
    $conflictPlan=$drafts->plan($conflictOrg);
    $assert($conflictPlan['blocked']===1 && $conflictPlan['create']===79,
        'Foreign canonical identity must block rather than merge by same ticker.');
    try {
        $drafts->apply($conflictOrg,1,100);
        throw new RuntimeException('Conflicting draft batch was not blocked.');
    } catch (RuntimeException $e) {
        $assert(str_contains($e->getMessage(),'blocked'),'Unexpected conflict exception.');
    }
    $assert($count('tn_capital_market_instruments',$conflictOrg)===1,
        'Conflicted draft batch must not partially create instruments.');
    echo "MySQL canonical bStock drafts: 80 DRAFT + audit/events, replay, no relationships/subscriptions, collision gate passed.\n";
} finally {
    foreach ([$org,$conflictOrg] as $scope) {
        foreach ([
            'tn_capital_market_instrument_identifiers',
            'tn_capital_market_instruments',
            'tn_capital_market_seed_import_batches',
            'tn_capital_market_seed_import_evidence',
        ] as $table) {
            $pdo->prepare('DELETE FROM '.$table.' WHERE organization_id=:org')->execute(['org'=>$scope]);
        }
    }
}
