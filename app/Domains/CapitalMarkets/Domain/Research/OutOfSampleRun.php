<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Research;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class OutOfSampleRun
{
    public function __construct(
        public string $id,
        public string $experimentId,
        public string $strategyVersionId,
        public string $datasetId,
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public string $criteriaHash,
        public string $parameterHash,
        public string $status,
        public DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $completedAt=null,
        public ?string $resultId=null,
    ){
        if($id===''||$strategyVersionId===''||$datasetId===''||$to<=$from||$criteriaHash===''||$parameterHash===''){
            throw new InvalidArgumentException('Invalid out-of-sample run.');
        }
    }
}
