<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Domains\Sales\Application\Contract\SalesDealStageHistoryStoreInterface;
use Domains\Sales\Application\DTO\SalesDealStageHistoryEntry;
use Domains\Sales\Application\Service\SalesDealStageHistoryProjector;
use Domains\Sales\Automation\Event\DealCreated;
use Domains\Sales\Automation\Event\DealStageChanged;
use Domains\Sales\Model\SalesHistoryQuality;
use Kernel\Event\EventMetadata;

$store = new class implements SalesDealStageHistoryStoreInterface {
    /** @var list<array<string,mixed>> */
    public array $rows = [];

    public function hasSourceEvent(string $organizationId, string $eventId): bool
    {
        foreach ($this->rows as $row) {
            if ($row['organization_id'] === $organizationId && $row['source_event_id'] === $eventId) return true;
        }
        return false;
    }

    public function currentStage(string $organizationId, string $dealId): ?array
    {
        for ($i = count($this->rows) - 1; $i >= 0; $i--) {
            $row = $this->rows[$i];
            if ($row['organization_id'] === $organizationId && $row['deal_id'] === $dealId && $row['left_at'] === null) {
                return $row;
            }
        }
        return null;
    }

    public function append(SalesDealStageHistoryEntry $entry): void
    {
        $this->rows[] = [
            'id' => $entry->id,
            'organization_id' => $entry->organizationId,
            'deal_id' => $entry->dealId,
            'pipeline_id' => $entry->pipelineId,
            'from_stage_id' => $entry->fromStageId,
            'from_stage_code' => $entry->fromStageCode,
            'to_stage_id' => $entry->toStageId,
            'to_stage_code' => $entry->toStageCode,
            'entered_at' => $entry->enteredAt,
            'left_at' => null,
            'duration_seconds' => null,
            'source_event_id' => $entry->sourceEventId,
            'correlation_id' => $entry->correlationId,
            'history_quality' => $entry->historyQuality->value,
        ];
    }

    public function closeCurrentStage(
        string $organizationId,
        string $historyId,
        \DateTimeImmutable $leftAt,
        SalesHistoryQuality $quality,
    ): void {
        foreach ($this->rows as &$row) {
            if ($row['organization_id'] === $organizationId && $row['id'] === $historyId && $row['left_at'] === null) {
                $row['left_at'] = $leftAt;
                $row['duration_seconds'] = $row['entered_at'] instanceof \DateTimeImmutable
                    ? max(0, $leftAt->getTimestamp() - $row['entered_at']->getTimestamp())
                    : null;
                $row['history_quality'] = $quality->value;
                return;
            }
        }
        unset($row);
        throw new RuntimeException('History row was not open.');
    }

    public function removeEstimatedCurrent(string $organizationId, string $dealId): void
    {
        $this->rows = array_values(array_filter($this->rows, static fn (array $row): bool =>
            !($row['organization_id'] === $organizationId
                && $row['deal_id'] === $dealId
                && $row['left_at'] === null
                && $row['history_quality'] === SalesHistoryQuality::Estimated->value)
        ));
    }

    public function clearOrganization(string $organizationId): void
    {
        $this->rows = array_values(array_filter(
            $this->rows,
            static fn (array $row): bool => $row['organization_id'] !== $organizationId
        ));
    }

    public function backfillCurrentState(string $organizationId): int { return 0; }
};

$projector = new SalesDealStageHistoryProjector($store);
$meta = new EventMetadata('corr-1', null, 'USER', '7');
$t0 = new \DateTimeImmutable('2026-09-13 10:00:00.000000');
$t1 = new \DateTimeImmutable('2026-09-13 10:30:00.000000');
$t2 = new \DateTimeImmutable('2026-09-13 11:45:00.000000');

$created = DealCreated::create('event-create', 'org-1', 'deal-1', 'pipe-1', 'stage-new', 'NEW', $meta, $t0);
if (!$projector->project($created)) throw new RuntimeException('DealCreated must create initial stage history.');
if ($projector->project($created)) throw new RuntimeException('Projection must be idempotent by source event.');

$changed1 = new \Kernel\Event\DomainEvent(
    'event-change-1', 'org-1', DealStageChanged::TYPE, 'deal', 'deal-1',
    [
        'pipeline_id' => 'pipe-1',
        'previous_stage_id' => 'stage-new',
        'previous_stage_code' => 'NEW',
        'stage_id' => 'stage-contacted',
        'stage_code' => 'CONTACTED',
    ],
    $meta,
    $t1,
);
if (!$projector->project($changed1)) throw new RuntimeException('First stage transition must project.');

$changed2 = new \Kernel\Event\DomainEvent(
    'event-change-2', 'org-1', DealStageChanged::TYPE, 'deal', 'deal-1',
    [
        'pipeline_id' => 'pipe-1',
        'previous_stage_id' => 'stage-contacted',
        'previous_stage_code' => 'CONTACTED',
        'stage_id' => 'stage-qualified',
        'stage_code' => 'QUALIFIED',
    ],
    $meta,
    $t2,
);
if (!$projector->project($changed2)) throw new RuntimeException('Second stage transition must project.');

if (count($store->rows) !== 3) throw new RuntimeException('Expected three history rows.');
if ($store->rows[0]['duration_seconds'] !== 1800) throw new RuntimeException('Initial stage duration must be 1800 seconds.');
if ($store->rows[1]['duration_seconds'] !== 4500) throw new RuntimeException('Contacted stage duration must be 4500 seconds.');
foreach ($store->rows as $row) {
    if ($row['history_quality'] !== SalesHistoryQuality::Complete->value) {
        throw new RuntimeException('Continuous canonical event chain must remain COMPLETE.');
    }
}

$orphan = new \Kernel\Event\DomainEvent(
    'event-orphan', 'org-1', DealStageChanged::TYPE, 'deal', 'deal-2',
    [
        'pipeline_id' => 'pipe-1',
        'previous_stage_id' => 'stage-new',
        'previous_stage_code' => 'NEW',
        'stage_id' => 'stage-contacted',
        'stage_code' => 'CONTACTED',
    ],
    $meta,
    $t1,
);
$projector->project($orphan);
$orphanRow = $store->currentStage('org-1', 'deal-2');
if (($orphanRow['history_quality'] ?? null) !== SalesHistoryQuality::Partial->value) {
    throw new RuntimeException('Transition without known initial stage must be PARTIAL.');
}

$lateCreated = DealCreated::create('event-late-create', 'org-1', 'deal-3', 'pipe-1', 'stage-new', 'NEW', $meta, $t2);
$projector->project($lateCreated);
$backwards = new \Kernel\Event\DomainEvent(
    'event-backwards', 'org-1', DealStageChanged::TYPE, 'deal', 'deal-3',
    [
        'pipeline_id' => 'pipe-1',
        'previous_stage_id' => 'stage-new',
        'previous_stage_code' => 'NEW',
        'stage_id' => 'stage-contacted',
        'stage_code' => 'CONTACTED',
    ],
    $meta,
    $t1,
);
$projector->project($backwards);
$backwardsRow = $store->currentStage('org-1', 'deal-3');
if (($backwardsRow['history_quality'] ?? null) !== SalesHistoryQuality::Partial->value) {
    throw new RuntimeException('Non-chronological transition must never remain COMPLETE.');
}

echo "Sales V0.8.1 stage history projector: OK\n";
