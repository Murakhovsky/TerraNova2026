<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Infrastructure\MarketData\L2;
use DomainException;
final readonly class L2SubscriptionConfig {
    public function __construct(public string $venue, public string $symbol, public int $depth = 50) {
        if (!preg_match('/^[A-Za-z0-9_\/\-]{2,40}$/D', $symbol)) throw new DomainException('Invalid symbol.');
        if (!in_array($venue, ['BINANCE_SPOT','BINANCE_FUTURES','BYBIT_SPOT','BYBIT_LINEAR','KRAKEN_SPOT'], true)) throw new DomainException('Invalid venue.');
        if ($depth < 10 || $depth > 1000) throw new DomainException('Invalid depth.');
        if (str_starts_with($venue, 'BYBIT_') && !in_array($depth, [50,200], true)) throw new DomainException('Invalid Bybit depth.');
        if ($venue === 'KRAKEN_SPOT' && !in_array($depth, [10,25,100,500,1000], true)) throw new DomainException('Invalid Kraken depth.');
    }
    public function websocketUrl(): string {
        return match ($this->venue) {
            'BINANCE_SPOT' => 'wss://data-stream.binance.vision:443/ws/'.strtolower($this->symbol).'@depth@100ms',
            'BINANCE_FUTURES' => 'wss://fstream.binance.com/public/ws/'.strtolower($this->symbol).'@depth@100ms',
            'BYBIT_SPOT' => 'wss://stream.bybit.com/v5/public/spot',
            'BYBIT_LINEAR' => 'wss://stream.bybit.com/v5/public/linear',
            'KRAKEN_SPOT' => 'wss://ws.kraken.com/v2',
        };
    }
    public function subscribeFrame(): ?array {
        return match ($this->venue) {
            'BYBIT_SPOT','BYBIT_LINEAR' => ['op'=>'subscribe','args'=>['orderbook.'.$this->depth.'.'.$this->symbol]],
            'KRAKEN_SPOT' => ['method'=>'subscribe','params'=>['channel'=>'book','symbol'=>[$this->symbol],'depth'=>$this->depth,'snapshot'=>true]],
            default => null,
        };
    }
    public function snapshotUrl(): ?string {
        return match ($this->venue) {
            'BINANCE_SPOT' => 'https://data-api.binance.vision/api/v3/depth?symbol='.$this->symbol.'&limit=1000',
            'BINANCE_FUTURES' => 'https://fapi.binance.com/fapi/v1/depth?symbol='.$this->symbol.'&limit=1000',
            default => null,
        };
    }
}
