<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\Persistence\MySql;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\MarketData\CanonicalMarketEvent;
use Domains\CapitalMarkets\Domain\MarketData\MarketCandle;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataMode;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataQualityAssessment;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;
use Domains\CapitalMarkets\Domain\MarketData\MarketObservation;
use Domains\CapitalMarkets\Domain\MarketData\MarketOrderBook;
use Domains\CapitalMarkets\Domain\MarketData\MarketQualityFlag;
use Domains\CapitalMarkets\Domain\MarketData\MarketQuote;
use Domains\CapitalMarkets\Domain\MarketData\MarketSession;
use Domains\CapitalMarkets\Domain\MarketData\MarketSnapshot;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\MarketData\MarketState;
use Domains\CapitalMarkets\Domain\MarketData\MarketStatus;
use Domains\CapitalMarkets\Domain\MarketData\MarketTimestamps;
use Domains\CapitalMarkets\Domain\MarketData\MarketTrade;
use Domains\CapitalMarkets\Domain\MarketData\MarketTrustStatus;
use Domains\CapitalMarkets\Domain\MarketData\MarketValueObservation;
use Domains\CapitalMarkets\Domain\MarketData\OrderBookLevel;
use Domains\CapitalMarkets\Domain\MarketData\ReferenceMarketState;
use Domains\CapitalMarkets\Domain\MarketData\ReferenceType;
use Domains\CapitalMarkets\Domain\MarketData\TradeSide;
use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\Price;
use Domains\CapitalMarkets\Domain\Value\Quantity;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use InvalidArgumentException;

final class MarketDataHydrator
{
    /** @param array<string,mixed> $row */
    public function canonicalEvent(array $row):CanonicalMarketEvent
    {
        $payload=$this->object((string)$row['payload_json']);
        $flags=$this->list((string)$row['quality_flags_json']);
        $type=MarketEventType::from((string)$row['event_type']);

        return new CanonicalMarketEvent(
            (string)$row['canonical_event_id'],
            MarketSourceId::fromString((string)$row['source_id']),
            $row['venue_id']===null?null:VenueId::fromString((string)$row['venue_id']),
            InstrumentId::fromString((string)$row['instrument_id']),
            new MarketTimestamps(
                new DateTimeImmutable((string)$row['source_timestamp']),
                new DateTimeImmutable((string)$row['received_timestamp']),
                new DateTimeImmutable((string)$row['processed_timestamp']),
            ),
            $row['sequence_value']===null?null:(string)$row['sequence_value'],
            $this->observation($type,$payload),
            array_map(static fn(string $flag):MarketQualityFlag=>MarketQualityFlag::from($flag),$flags),
            (int)$row['schema_version'],
            MarketDataMode::from((string)$row['data_mode']),
            MarketStatus::from((string)$row['market_status']),
        );
    }

    /** @param array<string,mixed> $data */
    public function marketState(array $data):MarketState
    {
        $latency=$this->array($data,'latency');
        $quality=new MarketDataQualityAssessment(
            MarketTrustStatus::from((string)$data['quality_status']),
            (int)$data['quality_score'],
            array_map(
                static fn(string $flag):MarketQualityFlag=>MarketQualityFlag::from($flag),
                $this->stringList($data['quality_flags']??[])
            ),
            (int)($latency['ingestion_ms']??0),
            (int)($latency['processing_ms']??0),
            (int)($latency['event_age_ms']??0),
            isset($data['reference_deviation_bps'])&&$data['reference_deviation_bps']!==null
                ?Decimal::fromString((string)$data['reference_deviation_bps'])
                :null,
        );

        return new MarketState(
            InstrumentId::fromString((string)$data['instrument_id']),
            VenueId::fromString((string)$data['venue_id']),
            MarketSourceId::fromString((string)$data['source_id']),
            is_array($data['last_trade']??null)?$this->trade($data['last_trade']):null,
            is_array($data['best_quote']??null)?$this->quote($data['best_quote'],MarketEventType::Bbo):null,
            is_array($data['order_book']??null)?$this->book($data['order_book'],MarketEventType::OrderBookSnapshot):null,
            isset($data['volume'])&&$data['volume']!==null?Decimal::fromString((string)$data['volume']):null,
            MarketStatus::from((string)$data['market_status']),
            new DateTimeImmutable((string)$data['source_timestamp']),
            new DateTimeImmutable((string)$data['updated_at']),
            $quality,
            (int)$data['state_version'],
            isset($data['last_sequence'])&&$data['last_sequence']!==null?(string)$data['last_sequence']:null,
            (string)$data['last_event_fingerprint'],
            MarketDataMode::from((string)($data['mode']??MarketDataMode::Live->value)),
        );
    }

    /** @param array<string,mixed> $data */
    public function referenceState(array $data):ReferenceMarketState
    {
        $qualityData=$this->array($data,'quality');
        $quality=new MarketDataQualityAssessment(
            MarketTrustStatus::from((string)$qualityData['status']),
            (int)$qualityData['score'],
            array_map(
                static fn(string $flag):MarketQualityFlag=>MarketQualityFlag::from($flag),
                $this->stringList($qualityData['flags']??[])
            ),
            (int)$qualityData['ingestion_latency_ms'],
            (int)$qualityData['processing_latency_ms'],
            (int)$qualityData['event_age_ms'],
            isset($qualityData['reference_deviation_bps'])&&$qualityData['reference_deviation_bps']!==null
                ?Decimal::fromString((string)$qualityData['reference_deviation_bps'])
                :null,
        );

        return new ReferenceMarketState(
            InstrumentId::fromString((string)$data['instrument_id']),
            MarketSourceId::fromString((string)$data['source_id']),
            is_array($data['current_quote']??null)?$this->quote($data['current_quote'],MarketEventType::Bbo):null,
            MarketSession::from((string)$data['session']),
            is_array($data['last_regular_market_quote']??null)?$this->quote($data['last_regular_market_quote'],MarketEventType::Bbo):null,
            is_array($data['last_extended_quote']??null)?$this->quote($data['last_extended_quote'],MarketEventType::Bbo):null,
            ReferenceType::from((string)$data['current_reference_type']),
            (int)$data['reference_age_ms'],
            $quality,
            new DateTimeImmutable((string)$data['updated_at']),
            (int)$data['state_version'],
            MarketDataMode::from((string)($data['mode']??MarketDataMode::Live->value)),
            isset($data['source_timestamp'])&&$data['source_timestamp']!==null
                ?new DateTimeImmutable((string)$data['source_timestamp'])
                :null,
            isset($data['last_sequence'])&&$data['last_sequence']!==null?(string)$data['last_sequence']:null,
            isset($data['last_event_fingerprint'])&&$data['last_event_fingerprint']!==null
                ?(string)$data['last_event_fingerprint']
                :null,
        );
    }

    /** @param array<string,mixed> $data */
    public function snapshot(array $data):MarketSnapshot
    {
        $states=[];
        foreach($data['instrument_states']??[] as $state){
            if(!is_array($state)||array_is_list($state))throw new InvalidArgumentException('Snapshot market state must be an object.');
            $states[]=$this->marketState($state);
        }

        $references=[];
        foreach($data['reference_states']??[] as $state){
            if(!is_array($state)||array_is_list($state))throw new InvalidArgumentException('Snapshot reference state must be an object.');
            $references[]=$this->referenceState($state);
        }

        $versions=[];
        $rawVersions=$data['source_versions']??[];
        if(!is_array($rawVersions)||array_is_list($rawVersions))throw new InvalidArgumentException('Snapshot source versions must be an object.');
        foreach($rawVersions as $source=>$version)$versions[(string)$source]=(int)$version;

        return new MarketSnapshot(
            (string)$data['snapshot_id'],
            new DateTimeImmutable((string)$data['created_at']),
            $states,
            $references,
            $versions,
        );
    }

    /** @param array<string,mixed> $payload */
    private function observation(MarketEventType $type,array $payload):MarketObservation
    {
        return match($type){
            MarketEventType::Quote,MarketEventType::Bbo=>$this->quote($payload,$type),
            MarketEventType::Trade=>$this->trade($payload),
            MarketEventType::OrderBookSnapshot,MarketEventType::OrderBookDelta=>$this->book($payload,$type),
            MarketEventType::Candle=>$this->candle($payload),
            MarketEventType::Volume,MarketEventType::ReferencePrice,MarketEventType::FundingRate,
            MarketEventType::OpenInterest,MarketEventType::MarkPrice,MarketEventType::IndexPrice
                =>new MarketValueObservation(
                    $type,
                    Decimal::fromString((string)$payload['value']),
                    isset($payload['unit'])&&$payload['unit']!==null?new AssetCode((string)$payload['unit']):null,
                ),
        };
    }

    /** @param array<string,mixed> $data */
    private function quote(array $data,MarketEventType $type):MarketQuote
    {
        return new MarketQuote(
            $this->price($this->array($data,'bid_price')),
            $this->quantity($this->array($data,'bid_quantity')),
            $this->price($this->array($data,'ask_price')),
            $this->quantity($this->array($data,'ask_quantity')),
            $type,
        );
    }

    /** @param array<string,mixed> $data */
    private function trade(array $data):MarketTrade
    {
        return new MarketTrade(
            (string)$data['trade_id'],
            $this->price($this->array($data,'price')),
            $this->quantity($this->array($data,'quantity')),
            isset($data['side'])&&$data['side']!==null?TradeSide::from((string)$data['side']):null,
        );
    }

    /** @param array<string,mixed> $data */
    private function book(array $data,MarketEventType $type):MarketOrderBook
    {
        $bids=[];$asks=[];
        foreach($data['bids']??[] as $row){
            if(!is_array($row)||array_is_list($row))throw new InvalidArgumentException('Book bid level must be an object.');
            $bids[]=$this->level($row);
        }
        foreach($data['asks']??[] as $row){
            if(!is_array($row)||array_is_list($row))throw new InvalidArgumentException('Book ask level must be an object.');
            $asks[]=$this->level($row);
        }

        return new MarketOrderBook(
            $type,
            isset($data['sequence'])&&$data['sequence']!==null?(string)$data['sequence']:null,
            $bids,
            $asks,
        );
    }

    /** @param array<string,mixed> $data */
    private function candle(array $data):MarketCandle
    {
        return new MarketCandle(
            new DateTimeImmutable((string)$data['open_time']),
            new DateTimeImmutable((string)$data['close_time']),
            $this->price($this->array($data,'open')),
            $this->price($this->array($data,'high')),
            $this->price($this->array($data,'low')),
            $this->price($this->array($data,'close')),
            Decimal::fromString((string)$data['volume']),
            (string)$data['source_type'],
        );
    }

    /** @param array<string,mixed> $data */
    private function level(array $data):OrderBookLevel
    {
        return new OrderBookLevel(
            $this->price($this->array($data,'price')),
            $this->quantity($this->array($data,'quantity')),
            isset($data['order_count'])&&$data['order_count']!==null?(int)$data['order_count']:null,
        );
    }

    /** @param array<string,mixed> $data */
    private function price(array $data):Price
    {
        return new Price(
            Decimal::fromString((string)$data['value']),
            new AssetCode((string)$data['base_asset']),
            new AssetCode((string)$data['quote_asset']),
            (int)$data['precision'],
        );
    }

    /** @param array<string,mixed> $data */
    private function quantity(array $data):Quantity
    {
        return new Quantity(
            Decimal::fromString((string)$data['value']),
            new AssetCode((string)$data['asset']),
            (int)$data['precision'],
        );
    }

    /** @return array<string,mixed> */
    private function object(string $json):array
    {
        $data=json_decode($json,true,flags:JSON_THROW_ON_ERROR);
        if(!is_array($data)||array_is_list($data))throw new InvalidArgumentException('Persisted market JSON must be an object.');
        return $data;
    }

    /** @return list<string> */
    private function list(string $json):array
    {
        return $this->stringList(json_decode($json,true,flags:JSON_THROW_ON_ERROR));
    }

    /** @return list<string> */
    private function stringList(mixed $data):array
    {
        if(!is_array($data)||!array_is_list($data))throw new InvalidArgumentException('Persisted market list must be a list.');
        $out=[];
        foreach($data as $item){
            if(!is_string($item))throw new InvalidArgumentException('Persisted market list item must be a string.');
            $out[]=$item;
        }
        return $out;
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function array(array $data,string $key):array
    {
        $value=$data[$key]??null;
        if(!is_array($value)||array_is_list($value))throw new InvalidArgumentException($key.' must be an object.');
        return $value;
    }
}
