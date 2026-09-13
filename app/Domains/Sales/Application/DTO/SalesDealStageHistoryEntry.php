<?php
declare(strict_types=1);

namespace Domains\Sales\Application\DTO;

use DateTimeImmutable;
use Domains\Sales\Model\SalesHistoryQuality;
use InvalidArgumentException;

final readonly class SalesDealStageHistoryEntry
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $dealId,
        public ?string $pipelineId,
        public ?string $fromStageId,
        public ?string $fromStageCode,
        public string $toStageId,
        public string $toStageCode,
        public ?DateTimeImmutable $enteredAt,
        public ?string $sourceEventId,
        public ?string $correlationId,
        public SalesHistoryQuality $historyQuality,
    ) {
        foreach ([
            'id' => $id,
            'organizationId' => $organizationId,
            'dealId' => $dealId,
            'toStageId' => $toStageId,
            'toStageCode' => $toStageCode,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException(sprintf('%s must not be empty.', $field));
            }
        }
    }
}
