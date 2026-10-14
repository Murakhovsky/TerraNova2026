<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\L2;

use DomainException;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;
use Domains\CapitalMarkets\Domain\MarketData\MarketOrderBook;
use Domains\CapitalMarkets\Domain\MarketData\OrderBookLevel;
use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\Price;
use Domains\CapitalMarkets\Domain\Value\Quantity;

/**
 * Converts validated exchange decimals into typed domain order books.
 * Never uses binary floating-point arithmetic.
 */
final readonly class L2DomainBookFactory
{
    public function __construct(
        private AssetCode $base,
        private AssetCode $quote,
        private int $pricePrecision = 30,
        private int $quantityPrecision = 30,
    ) {
        if ($base->equals($quote) || $pricePrecision < 0 || $pricePrecision > 30
            || $quantityPrecision < 0 || $quantityPrecision > 30) {
            throw new DomainException('Invalid L2 instrument precision or assets.');
        }
    }

    /** @param array<string,mixed> $frame */
    public function fromFrame(array $frame, ?string $sequence = null): MarketOrderBook
    {
        $type = match ($frame['type'] ?? null) {
            'snapshot' => MarketEventType::OrderBookSnapshot,
            'delta' => MarketEventType::OrderBookDelta,
            default => throw new DomainException('Unknown L2 frame type.'),
        };
        return $this->make($type, $frame['bids'] ?? null, $frame['asks'] ?? null, $sequence);
    }

    /** @param mixed $bids @param mixed $asks */
    public function make(MarketEventType $type, mixed $bids, mixed $asks, ?string $sequence = null): MarketOrderBook
    {
        if (!in_array($type, [MarketEventType::OrderBookSnapshot, MarketEventType::OrderBookDelta], true)) {
            throw new DomainException('Unsupported L2 observation type.');
        }
        return new MarketOrderBook($type, $sequence, $this->levels($bids), $this->levels($asks));
    }

    /** @return list<OrderBookLevel> */
    private function levels(mixed $rows): array
    {
        if (!is_array($rows) || !array_is_list($rows)) throw new DomainException('L2 levels must be a list.');
        $levels = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['price'], $row['qty'])
                || !is_string($row['price']) || !is_string($row['qty'])) {
                throw new DomainException('Invalid L2 level shape.');
            }
            $price = Decimal::fromString($row['price']);
            $quantity = Decimal::fromString($row['qty']);
            if (!$price->isPositive() || $quantity->isNegative()) throw new DomainException('Invalid L2 price or quantity.');
            $levels[] = new OrderBookLevel(
                new Price($price, $this->base, $this->quote, $this->pricePrecision),
                new Quantity($quantity, $this->base, $this->quantityPrecision),
            );
        }
        return $levels;
    }
}
