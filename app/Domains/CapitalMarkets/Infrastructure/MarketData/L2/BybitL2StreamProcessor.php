<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\L2;

use DomainException;
use Domains\CapitalMarkets\Domain\Service\BybitL2RecoveryCoordinator;
use Domains\CapitalMarkets\Domain\Service\L2RecoverySession;

/** Serial Bybit snapshot/delta pipeline. */
final class BybitL2StreamProcessor
{
    private int $epoch = 0;
    public function __construct(
        private readonly L2FrameDispatch $frames,
        private readonly L2DomainBookFactory $books,
        private readonly BybitL2RecoveryCoordinator $coordinator,
        private readonly L2RecoverySession $session,
    ) {}

    public function begin(): int { return $this->epoch = $this->coordinator->begin(); }

    public function receive(string $json): void
    {
        $frame = $this->frames->decode($json);
        if ($frame === null) return;
        $book = $this->books->fromFrame($frame, $frame['seq']);
        if ($frame['type'] === 'snapshot') {
            // Bybit sends a new snapshot after a service restart. Start a new
            // recovery epoch rather than publishing over a trusted session.
            if ($this->session->status() === 'TRUSTED') $this->begin();
            $this->coordinator->snapshot($this->epoch, $book, $frame['u'], $frame['seq']);
            return;
        }
        if ($this->session->trustedBook($this->epoch) === null) {
            throw new DomainException('Bybit delta received before snapshot.');
        }
        $this->coordinator->delta($this->epoch, $book, $frame['u'], $frame['seq']);
    }

    public function trustedBook(): ?\Domains\CapitalMarkets\Domain\MarketData\MarketOrderBook
    {
        return $this->session->trustedBook($this->epoch);
    }

    public function diagnostics(): array { return $this->session->diagnostics(); }
}
