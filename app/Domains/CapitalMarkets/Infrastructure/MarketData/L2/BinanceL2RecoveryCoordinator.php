<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\L2;

use DomainException;
use Domains\CapitalMarkets\Domain\Service\L2RecoverySession;
use Domains\CapitalMarkets\Domain\Service\OrderBookRebuilder;
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
        if ($this->session->trustedBook($epoch) !== null) {
            $this->applyLive($epoch, $event);
            return;
        }
        $this->session->buffer($epoch, $event, $bytes);
    }

    public function reconcile(int $epoch, MarketOrderBook $snapshot, string $snapshotId): void
    {
        if ($this->session->diagnostics()['epoch'] !== $epoch || $this->session->status() === 'TRUSTED') {
            throw new DomainException('Stale Binance snapshot epoch.');
        }
        if ($snapshot->eventType() !== MarketEventType::OrderBookSnapshot) {
            $this->session->invalidateIfCurrent($epoch, 'INVALID_BINANCE_SNAPSHOT');
            throw new DomainException('Binance recovery requires REST snapshot.');
        }
        $book = $snapshot;
        try {
            // Drain again after each batch: events arriving during snapshot replay
            // must be applied before exposing the book as trusted.
            do {
                $events = $this->session->drain($epoch);
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
            } while ($events !== []);
            if (!$this->bridged) {
                // A REST snapshot without a covering delta is not a proven live book.
                // Keep the session untrusted and request a new recovery cycle.
                $this->session->invalidateIfCurrent($epoch, 'BINANCE_SNAPSHOT_BRIDGE_MISSING');
                return;
            }
            $this->session->publishVerified($epoch, $book, $this->sequence->last());
        } catch (\Throwable $error) {
            $this->session->invalidateIfCurrent($epoch, 'BINANCE_RECONCILIATION_FAILED');
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
            $this->session->invalidateIfCurrent($epoch, 'BINANCE_LIVE_SEQUENCE_FAILED');
            throw $error;
        }
    }
}
