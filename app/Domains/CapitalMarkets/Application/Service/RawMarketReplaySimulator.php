<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Application\Service;

use DateTimeImmutable;
use DomainException;
use Domains\CapitalMarkets\Application\Contract\{
    MarketDataNormalizerInterface,MarketSourceRepositoryInterface,RawMarketEventRepositoryInterface
};
use Domains\CapitalMarkets\Domain\MarketData\{MarketDataMode,MarketSourceId,RawMarketEvent};
use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\MysqlRawMarketProvenance;
use Throwable;

/**
 * Explicitly isolated REPLAY lane. Decodes and normalizes in memory only;
 * never calls market ingestion, worker leases, execution, or event bus.
 * Refuses legacy/raw entries without a matching immutable provenance receipt.
 */
final readonly class RawMarketReplaySimulator
{
    public function __construct(
        private RawMarketEventRepositoryInterface $history,
        private MarketSourceRepositoryInterface $sources,
        private MarketDataDecoderRegistry $decoders,
        private MarketDataNormalizerInterface $normalizer,
        private MysqlRawMarketProvenance $proof,
    ){}

    public function run(string $org,string $source,DateTimeImmutable $from,
        DateTimeImmutable $to,int $limit=100):array
    {
        if($org===''||strlen($org)>190||$source===''||strlen($source)>190
            ||$limit<1||$limit>200||$to<$from
            ||($to->getTimestamp()-$from->getTimestamp())>604800){
            throw new DomainException('Invalid bounded read-only replay range.');
        }
        $sourceId=MarketSourceId::fromString($source);
        $desc=$this->sources->get($org,$sourceId);
        if($desc===null)throw new DomainException('Replay source is not registered.');
        $events=$this->history->range($org,$sourceId,$from,$to,$limit);
        $out=[];$invalid=0;
        foreach($events as $raw){
            if(!$this->proof->verifyEvent($org,$raw)){
                $invalid++;
                $out[]=['raw_event_id'=>$raw->eventId,'status'=>'PROVENANCE_FAILED'];
                continue;
            }
            try{
                $replay=new RawMarketEvent(
                    $raw->eventId,$raw->sourceId,$raw->venueId,$raw->externalInstrument,
                    $raw->eventType,$raw->providerTimestamp,$raw->receivedAt,$raw->sequence,
                    $raw->rawPayload,$raw->transportMetadata,MarketDataMode::Replay,
                );
                $decoded=$this->decoders->get($desc->adapterType)->decode($desc,$replay);
                if($decoded->mode!==MarketDataMode::Replay){
                    throw new DomainException('Decoder escaped read-only REPLAY mode.');
                }
                $canon=$this->normalizer->normalize($org,$desc,$replay,$decoded);
                if($canon->mode!==MarketDataMode::Replay){
                    throw new DomainException('Canonical normalizer escaped REPLAY lane.');
                }
                $out[]=[
                    'raw_event_id'=>$raw->eventId,
                    'canonical_instrument_id'=>$canon->instrumentId->value(),
                    'event_type'=>$canon->eventType()->value,
                    'source_timestamp'=>$canon->timestamps->sourceTimestamp->format(DATE_ATOM),
                    'status'=>'REPLAY_DECODED',
                ];
            }catch(Throwable){
                $invalid++;
                $out[]=['raw_event_id'=>$raw->eventId,'status'=>'REPLAY_QUARANTINED'];
            }
        }
        return ['status'=>$invalid>0?'REPLAY_INCOMPLETE':'REPLAY_VERIFIED',
            'organization_id'=>$org,'source_id'=>$source,'events'=>$out,
            'decoded'=>count($out)-$invalid,'quarantined'=>$invalid,
            'simulation_only'=>true,'no_market_data_ingestion'=>true,
            'no_execution_or_opportunity_side_effects'=>true];
    }
}
