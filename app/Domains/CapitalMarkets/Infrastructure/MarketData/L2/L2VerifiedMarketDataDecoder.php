<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\L2;

use Domains\CapitalMarkets\Application\Contract\MarketDataDecoderInterface;
use Domains\CapitalMarkets\Application\DTO\DecodedMarketEvent;
use Domains\CapitalMarkets\Domain\MarketData\{MarketEventType,MarketSourceDescriptor,MarketStatus,MarketSession,ReferenceType,RawMarketEvent};
use DomainException;

/** Decodes only independently verified full L2 book snapshots. */
final readonly class L2VerifiedMarketDataDecoder implements MarketDataDecoderInterface
{
    public const ADAPTER_TYPE = 'l2.verified';

    public function adapterType(): string { return self::ADAPTER_TYPE; }

    public function decode(MarketSourceDescriptor $source, RawMarketEvent $event): DecodedMarketEvent
    {
        if ($source->adapterType !== self::ADAPTER_TYPE
            || $event->eventType !== 'l2.verified.snapshot'
            || ($event->transportMetadata['recovery'] ?? null) !== 'VERIFIED') {
            throw new DomainException('Unverified or mismatched L2 market event.');
        }
        $book = $event->rawPayload['book'] ?? null;
        if (!is_array($book) || array_is_list($book)
            || !isset($book['bids'],$book['asks'])
            || !is_array($book['bids']) || !array_is_list($book['bids'])
            || !is_array($book['asks']) || !array_is_list($book['asks'])
            || $event->sequence === null) {
            throw new DomainException('Invalid verified L2 snapshot.');
        }
        foreach ([$book['bids'],$book['asks']] as $side) {
            foreach ($side as $level) {
                if (!is_array($level) || array_is_list($level)) throw new DomainException('Invalid L2 level.');
                foreach (['price','quantity'] as $field) {
                    $value = $level[$field] ?? null;
                    if (!is_string($value) || strlen($value)>100
                        || preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D',$value)!==1) {
                        throw new DomainException('Invalid verified L2 decimal.');
                    }
                }
            }
        }
        return new DecodedMarketEvent(
            MarketEventType::OrderBookSnapshot,$event->externalInstrument,
            $event->receivedAt,$event->sequence,
            ['bids'=>$book['bids'],'asks'=>$book['asks']],
            $event->mode,MarketStatus::Unknown,MarketSession::Unknown,ReferenceType::ProviderReference
        );
    }
}
