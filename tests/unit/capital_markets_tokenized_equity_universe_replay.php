<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Application\Service\TokenizedEquityHistoricalReplayService;
use Domains\CapitalMarkets\Domain\Service\TokenizedEquitySpreadDetector;
use Domains\CapitalMarkets\Domain\Service\NetEconomicsEngine;
use Domains\CapitalMarkets\Application\Contract\MarketSnapshotRepositoryInterface;
use Domains\CapitalMarkets\Domain\MarketData\MarketSnapshot;

require dirname(__DIR__,2).'/vendor/autoload.php';

$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};

$repo=new class implements MarketSnapshotRepositoryInterface{
    public ?MarketSnapshot $snapshot=null;
    public function save(string $organizationId,MarketSnapshot $snapshot):void{$this->snapshot=$snapshot;}
    public function get(string $organizationId,string $snapshotId):?MarketSnapshot{return $this->snapshot?->snapshotId===$snapshotId?$this->snapshot:null;}
    public function list(string $organizationId,int $limit=500):array{return $this->snapshot===null?[]:[$this->snapshot];}
};
$repo->snapshot=new MarketSnapshot('snapshot-1',new DateTimeImmutable('2026-10-06T10:00:00+00:00'),[],[],['source-a'=>3]);
$service=new TokenizedEquityHistoricalReplayService($repo,new TokenizedEquitySpreadDetector(),new NetEconomicsEngine());
$options=['buy_fee_rate'=>'0.001','sell_fee_rate'=>'0.001'];
$a=$service->replay('org',$options);
$b=$service->replay('org',$options);
$assert($a['dataset_hash']===$b['dataset_hash'],'Historical replay dataset hash must be deterministic.');
$assert($a['mutated']===false,'Historical replay must be read-only.');
$assert($a['instrument_state_count']===0&&$a['reference_state_count']===0,'Historical replay counts drifted.');

echo "Capital Markets Tokenized Equity universe/replay passed.\n";
