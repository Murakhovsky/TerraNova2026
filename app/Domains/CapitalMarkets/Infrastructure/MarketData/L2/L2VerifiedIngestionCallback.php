<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\L2;

use DomainException;
use Domains\CapitalMarkets\Domain\MarketData\MarketOrderBook;

/**
 * Publish callback for L2StreamWorker. Ingestion rejection is a fatal
 * delivery failure, never counted as a successful book publication.
 */
final readonly class L2VerifiedIngestionCallback
{
    public function __construct(private L2IngestionPublisher $publisher) {}

    public function __invoke(MarketOrderBook $book): void
    {
        $result = $this->publisher->publish($book);
        if (!$result->rawStored || !$result->canonicalStored || !$result->stateUpdated) {
            throw new DomainException('Verified L2 ingestion did not update canonical market state: '.$result->status.($result->reason !== null ? ' ('.$result->reason.')' : ''));
        }
    }
}
