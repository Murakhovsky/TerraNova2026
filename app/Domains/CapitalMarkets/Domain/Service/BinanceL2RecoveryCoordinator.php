<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Service;

use DomainException;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;
use Domains\CapitalMarkets\Domain\MarketData\MarketOrderBook;

/**
 * Coordinates buffered Binance diff-depth events and a REST snapshot.
 * Caller must feed events in transport arrival order for the same stream.
 */
final class BinanceL2RecoveryCoordinator
{
    private BinanceL2SequenceValidator $sequence;
    private OrderBookRebuilder $rebuilder;
    private bool $bridged = false;

    public function __construct(
        private readonly L2RecoverySession $session,
        bool $futures,
    ) {
        $this->sequence = new BinanceL2SequenceValidator($futures);
        $this->rebuilder = new OrderBookRebuilder();
    }

    public function begin(): int
    {
        $this->sequence->reset();
        $this->bridged = false;
        return $this->session->begin();
    }

    /** @param array{U:string,u:string,pu?:string,book:MarketOrderBook} $event */
    public function buffer(int $epoch, array $event, int $bytes): void
    {
        $this->session->buffer($epoch, $event, $bytes);
    }

    public function reconcile(int $epoch, MarketOrderBook $snapshot, string $snapshotId): void
    {
        if ($snapshot->eventType() !== MarketEventType::OrderBookSnapshot) {
            throw new DomainException('Binance recovery requires REST snapshot.');
        }
        $events = $this->session->drain($epoch);
        $book = $snapshot;
        try {
            foreach ($events as $entry) {
                $event = $entry['payload'];
                if (!$this->bridged) {
                    if (!$this->sequence->acceptFirst($snapshotId, $event['U'], $event['u'])) continue;
                    $this->bridged = true;
                } elseif (!$this->sequence->acceptNext($event['U'], $event['u'], $event['pu'] ?? null)) {
                    continue;
                }
                $book = $this->rebuilder->apply($book, $event['book']);
            }
            if (!$this->bridged) {
                // No verified bridge yet: never publish a snapshot as live trusted L2.
                return;
            }
            $this->session->publishVerified($epoch, $book, $this->sequence->last());
        } catch (\Throwable $error) {
            $this->session->invalidate('BINANCE_RECONCILIATION_FAILED');
            throw $error;
        }
    }

    /** @param array{U:string,u:string,pu?:string,book:MarketOrderBook} $event */
    public function applyLive(int $epoch, array $event): void
    {
        $book = $this->session->trustedBook($epoch);
        if ($book === null) throw new DomainException('No trusted Binance L2 book.');
        try {
            if (!$this->sequence->acceptNext($event['U'], $event['u'], $event['pu'] ?? null)) return;
            $next = $this->rebuilder->apply($book, $event['book']);
            $this->session->publishVerified($epoch, $next, $this->sequence->last());
        } catch (\Throwable $error) {
            $this->session->invalidate('BINANCE_LIVE_SEQUENCE_FAILED');
            throw $error;
        }
    }
}
