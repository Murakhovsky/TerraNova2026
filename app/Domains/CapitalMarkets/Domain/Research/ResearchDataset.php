<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Research;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ResearchDataset
{
    /** @param list<string> $dataSources @param list<string> $instrumentUniverse @param list<string> $venueUniverse
     *  @param list<string> $dataTypes @param array<string,mixed> $qualityProfile @param list<array<string,mixed>> $gaps
     *  @param array<string,mixed> $lineage
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $dataSources,
        public array $instrumentUniverse,
        public array $venueUniverse,
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public array $dataTypes,
        public string $resolution,
        public array $qualityProfile,
        public array $gaps,
        public DatasetQualityStatus $qualityStatus,
        public DateTimeImmutable $createdAt,
        public string $snapshotHash,
        public string $schemaVersion,
        public array $lineage,
    ){
        if(trim($id)===''||trim($name)===''||$to<=$from||trim($snapshotHash)===''){
            throw new InvalidArgumentException('Invalid research dataset snapshot.');
        }
    }

    public function canRunExperiment():bool
    {
        return $this->qualityStatus!==DatasetQualityStatus::Unsuitable;
    }
}
