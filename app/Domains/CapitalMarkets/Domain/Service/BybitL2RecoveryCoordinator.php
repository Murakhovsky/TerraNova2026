<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Service;

use DomainException;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;
use Domains\CapitalMarkets\Domain\MarketData\MarketOrderBook;

/**
 * Bybit V5 snapshot/delta order-book session.
 * u is an update ID, seq is a cross-sequence (not a gapless counter).
 * A fresh snapshot replaces the book; u=1 signals a service restart.
 */
final class BybitL2RecoveryCoordinator
{
    private OrderBookRebuilder $rebuilder;
    private ?string $updateId = null;
    private ?string $crossSequence = null;

    public function __construct(private readonly L2RecoverySession $session)
    {
        $this->rebuilder = new OrderBookRebuilder();
    }

    public function begin(): int
    {
        $this->updateId = null;
        $this->crossSequence = null;
        return $this->session->begin();
    }

    public function snapshot(int $epoch, MarketOrderBook $snapshot, string $updateId, string $crossSequence): void
    {
        $this->validate($updateId, $crossSequence);
        if ($snapshot->eventType() !== MarketEventType::OrderBookSnapshot) {
            $this->session->invalidateIfCurrent($epoch, 'BYBIT_INVALID_SNAPSHOT');
            throw new DomainException('Bybit snapshot expected.');
        }
        if ($this->session->diagnostics()['buffered'] !== 0) {
            $this->session->invalidateIfCurrent($epoch, 'BYBIT_UNRECONCILED_BUFFER');
            throw new DomainException('Bybit snapshot requires replay of buffered deltas.');
        }
        $this->session->drain($epoch);
        $this->updateId = $updateId;
        $this->crossSequence = $crossSequence;
        $this->session->publishVerified($epoch, $snapshot, $crossSequence);
    }

    public function delta(int $epoch, MarketOrderBook $delta, string $updateId, string $crossSequence): void
    {
        try {
            $this->validate($updateId, $crossSequence);
        } catch (\Throwable $error) {
            $this->session->invalidateIfCurrent($epoch, 'BYBIT_INVALID_SEQUENCE');
            throw $error;
        }
        $book = $this->session->trustedBook($epoch);
        if ($book === null) throw new DomainException('Bybit book is not trusted.');
        try {
            if ($updateId === '1') {
                throw new DomainException('Bybit update ID reset requires fresh snapshot.');
            }
            if ($delta->eventType() !== MarketEventType::OrderBookDelta) {
                throw new DomainException('Bybit delta event expected.');
            }
            if ($this->compare($updateId, $this->updateId) <= 0) return;
            if ($this->compare($crossSequence, $this->crossSequence) <= 0) {
                throw new DomainException('Bybit cross-sequence rollback.');
            }
            $next = $this->rebuilder->apply($book, $delta);
            $this->session->publishVerified($epoch, $next, $crossSequence);
            $this->updateId = $updateId;
            $this->crossSequence = $crossSequence;
        } catch (\Throwable $error) {
            $this->session->invalidateIfCurrent($epoch, 'BYBIT_SEQUENCE_INVALID');
            throw $error;
        }
    }

    private function validate(string ...$values): void
    {
        foreach ($values as $value) {
            if ($value === '' || !ctype_digit($value)) throw new DomainException('Invalid Bybit sequence.');
        }
    }

    private function compare(string $a, ?string $b): int
    {
        if ($b === null) throw new DomainException('Bybit snapshot not initialized.');
        $a = ltrim($a, '0') ?: '0';
        $b = ltrim($b, '0') ?: '0';
        return strlen($a) <=> strlen($b) ?: strcmp($a, $b);
    }
}
