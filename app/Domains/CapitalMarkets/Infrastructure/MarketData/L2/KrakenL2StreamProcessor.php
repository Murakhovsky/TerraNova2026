<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\L2;

use DomainException;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;
use Domains\CapitalMarkets\Domain\MarketData\MarketOrderBook;
use Domains\CapitalMarkets\Domain\Service\KrakenL2BookRecovery;

/** Kraken v2 CRC-verified order-book stream. */
final class KrakenL2StreamProcessor
{
    private int $generation = 0;
    public function __construct(
        private readonly L2FrameDispatch $frames,
        private readonly L2DomainBookFactory $books,
        private readonly KrakenL2BookRecovery $recovery,
    ) {}

    public function begin(): int { return $this->generation = $this->recovery->begin(); }

    public function receive(string $json): void
    {
        $frame = $this->frames->decode($json);
        if ($frame === null) return;
        if ($frame['type'] === 'snapshot') {
            $this->recovery->snapshot($this->generation, $frame['bids'], $frame['asks'], $frame['checksum']);
        } elseif ($frame['type'] === 'delta') {
            $this->recovery->delta($this->generation, $frame['bids'], $frame['asks'], $frame['checksum']);
        } else {
            throw new DomainException('Unexpected Kraken frame type.');
        }
    }

    public function trustedBook(): ?MarketOrderBook
    {
        $book = $this->recovery->trustedBook($this->generation);
        if ($book === null) return null;
        return $this->books->make(MarketEventType::OrderBookSnapshot, $book['bids'], $book['asks'], (string)$book['version']);
    }

    public function diagnostics(): array { return $this->recovery->diagnostics(); }
}
