<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Service;

use Domains\Sales\Application\Contract\SalesDealStageHistoryStoreInterface;
use Domains\Sales\Application\Contract\SalesHistoricalEventStreamInterface;
use InvalidArgumentException;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class SalesDealStageHistoryRebuilder
{
    public function __construct(
        private SalesHistoricalEventStreamInterface $events,
        private SalesDealStageHistoryProjector $projector,
        private SalesDealStageHistoryStoreInterface $history,
        private TransactionManagerInterface $transactions,
    ) {
    }

    /** @return array{events:int,projected:int,backfilled:int} */
    public function rebuildOrganization(string $organizationId): array
    {
        $organizationId = trim($organizationId);
        if ($organizationId === '') {
            throw new InvalidArgumentException('Organization id is required for Sales history rebuild.');
        }

        return $this->transactions->transactional(function () use ($organizationId): array {
            $this->history->clearOrganization($organizationId);
            $events = 0;
            $projected = 0;

            foreach ($this->events->forOrganization($organizationId) as $event) {
                $events++;
                if ($this->projector->project($event)) {
                    $projected++;
                }
            }

            $backfilled = $this->history->backfillCurrentState($organizationId);

            return [
                'events' => $events,
                'projected' => $projected,
                'backfilled' => $backfilled,
            ];
        });
    }
}
