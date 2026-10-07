<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Research;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ResearchPaperRun
{
    /** @param list<string> $executionIds @param array<string,mixed> $performanceSnapshot */
    public function __construct(
        public string $id,
        public string $experimentId,
        public string $strategyVersionId,
        public array $executionIds,
        public string $status,
        public DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $completedAt=null,
        public array $performanceSnapshot=[],
        public ?string $resultId=null,
        public ?string $decisionId=null,
    ){
        if(trim($id)===''||trim($experimentId)===''||trim($strategyVersionId)===''){
            throw new InvalidArgumentException('Invalid research paper run identity.');
        }
        if($executionIds===[])throw new InvalidArgumentException('Research paper run requires at least one paper execution.');
        foreach($executionIds as $executionId){
            if(!is_string($executionId)||trim($executionId)==='')throw new InvalidArgumentException('Invalid paper execution id.');
        }
        if(!in_array($status,['RUNNING','COMPLETED','FAILED','CANCELLED','INVALIDATED'],true)){
            throw new InvalidArgumentException('Invalid research paper run status.');
        }
        if($completedAt!==null&&$completedAt<$startedAt)throw new InvalidArgumentException('Paper run completion cannot precede start.');
    }
}
