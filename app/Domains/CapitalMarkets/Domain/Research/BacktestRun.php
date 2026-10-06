<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Research;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class BacktestRun
{
    public function __construct(
        public string $id,
        public string $experimentId,
        public string $strategyVersionId,
        public string $datasetId,
        public DataPartition $partition,
        public string $executionModelVersion,
        public string $capital,
        public string $riskProfileVersion,
        public string $executionFidelity,
        public int $randomSeed,
        public string $applicationBuild,
        public string $commitReference,
        public DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $completedAt,
        public string $status,
        public ?string $resultId=null,
    ){
        if($id===''||$experimentId===''||$strategyVersionId===''||$datasetId===''){
            throw new InvalidArgumentException('Invalid backtest run identity.');
        }
        if(!in_array($executionFidelity,['HIGH','MEDIUM','LIMITED','SYNTHETIC'],true)){
            throw new InvalidArgumentException('Invalid execution fidelity.');
        }
        if(!in_array($status,['QUEUED','RUNNING','COMPLETED','FAILED','CANCELLED','INVALIDATED'],true)){
            throw new InvalidArgumentException('Invalid backtest run status.');
        }
    }

    public function reproducibilityFingerprint():string
    {
        return hash('sha256',implode('|',[
            $this->datasetId,$this->strategyVersionId,$this->executionModelVersion,
            $this->capital,$this->riskProfileVersion,(string)$this->randomSeed,
            $this->applicationBuild,$this->commitReference,$this->partition->value,
        ]));
    }
}
