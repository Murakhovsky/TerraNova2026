<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\L2;

use DomainException;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;

use Domains\CapitalMarkets\Domain\Service\L2RecoverySession;

/**
 * Binance diff-depth orchestration. Caller must start websocket buffering
 * before requesting the REST snapshot, and dispatch events serially.
 */
final class BinanceL2StreamProcessor
{
    private int $epoch = 0;

    public function __construct(
        private readonly L2FrameDispatch $frames,
        private readonly L2DomainBookFactory $books,
        private readonly BinanceL2RecoveryCoordinator $coordinator,
        private readonly L2RecoverySession $session,
    ) {}

    public function begin(): int
    {
        return $this->epoch = $this->coordinator->begin();
    }

    public function receive(string $json): void
    {
        $frame = $this->frames->decode($json);
        if ($frame === null) return;
        if (($frame['type'] ?? null) !== 'delta') throw new DomainException('Binance diff-depth event expected.');
        $event = [
            'U'=>$frame['U'], 'u'=>$frame['u'],
            'book'=>$this->books->fromFrame($frame, $frame['u']),
        ];
        if (isset($frame['pu'])) $event['pu'] = $frame['pu'];
        $this->coordinator->buffer($this->epoch, $event, strlen($json));
    }

    /** @param array{sequence:string,bids:array,asks:array} $snapshot */
    public function reconcile(array $snapshot): void
    {
        $book = $this->books->make(
            MarketEventType::OrderBookSnapshot,
            $snapshot['bids'], $snapshot['asks'], $snapshot['sequence'],
        );
        $this->coordinator->reconcile($this->epoch, $book, $snapshot['sequence']);
    }


    public function canBridge(string $snapshotId): bool
    {
        $target = $this->increment($snapshotId);
        foreach ($this->session->bufferedSequenceRanges($this->epoch) as $range) {
            if ($this->compare($range['U'], $target) <= 0 && $this->compare($range['u'], $target) >= 0) {
                return true;
            }
        }
        return false;
    }

    public function snapshotTooOld(string $snapshotId): bool
    {
        $target = $this->increment($snapshotId);
        $ranges = $this->session->bufferedSequenceRanges($this->epoch);
        return $ranges !== [] && $this->compare($ranges[0]['U'], $target) > 0;
    }

    private function compare(string $a, string $b): int
    {
        if (!ctype_digit($a) || !ctype_digit($b)) throw new DomainException('Invalid Binance sequence.');
        $a = ltrim($a, '0') ?: '0';
        $b = ltrim($b, '0') ?: '0';
        return strlen($a) <=> strlen($b) ?: strcmp($a, $b);
    }

    private function increment(string $id): string
    {
        if (!ctype_digit($id)) throw new DomainException('Invalid Binance sequence.');
        $digits = str_split(ltrim($id, '0') ?: '0');
        for ($i = count($digits) - 1; $i >= 0; --$i) {
            if ($digits[$i] !== '9') {
                $digits[$i] = (string) ((int) $digits[$i] + 1);
                return implode('', $digits);
            }
            $digits[$i] = '0';
        }
        return '1'.implode('', $digits);
    }

    public function trustedBook(): ?\Domains\CapitalMarkets\Domain\MarketData\MarketOrderBook
    {
        return $this->session->trustedBook($this->epoch);
    }

    public function diagnostics(): array { return $this->session->diagnostics(); }
}
