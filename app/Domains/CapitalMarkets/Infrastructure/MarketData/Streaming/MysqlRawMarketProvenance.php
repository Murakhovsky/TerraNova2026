<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Infrastructure\MarketData\Streaming;

use DomainException;
use Domains\CapitalMarkets\Domain\MarketData\RawMarketEvent;
use PDO;

/** Per-event SHA256 provenance, no high-contention global hash head. */
final readonly class MysqlRawMarketProvenance
{
    public function __construct(private PDO $pdo){}

    /** @param mixed $value */
    private static function canonical(mixed $value):mixed
    {
        if(!is_array($value))return $value;
        if(!array_is_list($value))ksort($value,SORT_STRING);
        foreach($value as $key=>$part)$value[$key]=self::canonical($part);
        return $value;
    }

    /** @param array<string,mixed> $payload */
    public static function jsonHash(array $payload):string
    {
        return hash('sha256',json_encode((object)self::canonical($payload),
            JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }
    public static function envelope(string $org,string $source,string $event,string $symbol,
        string $type,string $received,?string $sequence,string $mode,
        string $payloadHash,string $metadataHash):string
    {
        return hash('sha256',json_encode(
            ['v'=>1,'org'=>$org,'source'=>$source,'id'=>$event,'symbol'=>$symbol,
             'type'=>$type,'received'=>$received,'sequence'=>$sequence,'mode'=>$mode,
             'payload'=>$payloadHash,'metadata'=>$metadataHash],
            JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES,
        ));
    }

    public function record(string $org,RawMarketEvent $raw):void
    {
        if(!$this->pdo->inTransaction())throw new DomainException('Provenance must commit with raw event.');
        $payload=self::jsonHash($raw->rawPayload);
        $metadata=self::jsonHash($raw->transportMetadata);
        $digest=self::envelope($org,$raw->sourceId->value(),$raw->eventId,
            $raw->externalInstrument,$raw->eventType,
            $raw->receivedAt->format('Y-m-d H:i:s.u'),$raw->sequence,$raw->mode->value,
            $payload,$metadata);
        $q=$this->pdo->prepare(
            'INSERT INTO tn_capital_market_raw_provenance
             (organization_id,raw_event_id,source_id,payload_sha256,metadata_sha256,envelope_sha256)
             VALUES (:org,:id,:source,:payload,:metadata,:digest)');
        $q->execute(['org'=>$org,'id'=>$raw->eventId,'source'=>$raw->sourceId->value(),
            'payload'=>$payload,'metadata'=>$metadata,'digest'=>$digest]);
    }

    /** Verify the exact raw DTO against its persisted, immutable attestation. */
    public function verifyEvent(string $org,RawMarketEvent $raw):bool
    {
        if($org===''||strlen($org)>190||$this->pdo->inTransaction()){
            throw new DomainException('Provenance verification requires isolated read.');
        }
        $q=$this->pdo->prepare(
            'SELECT payload_sha256,metadata_sha256,envelope_sha256
             FROM tn_capital_market_raw_provenance
             WHERE organization_id=:org AND raw_event_id=:event AND source_id=:source');
        $q->execute(['org'=>$org,'event'=>$raw->eventId,'source'=>$raw->sourceId->value()]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))return false;
        $ph=self::jsonHash($raw->rawPayload);
        $mh=self::jsonHash($raw->transportMetadata);
        $digest=self::envelope($org,$raw->sourceId->value(),$raw->eventId,
            $raw->externalInstrument,$raw->eventType,
            $raw->receivedAt->format('Y-m-d H:i:s.u'),$raw->sequence,
            $raw->mode->value,$ph,$mh);
        return hash_equals((string)$row['payload_sha256'],$ph)
            &&hash_equals((string)$row['metadata_sha256'],$mh)
            &&hash_equals((string)$row['envelope_sha256'],$digest);
    }

    /** Bounded read-only, resumable audit. No raw values or credentials in output. */
    public function verify(string $org,string $source,int $afterId=0,int $limit=200):array
    {
        if($org===''||strlen($org)>190||$source===''||strlen($source)>190
            ||$afterId<0||$limit<1||$limit>500||$this->pdo->inTransaction()){
            throw new DomainException('Invalid isolated raw provenance audit.');
        }
        $q=$this->pdo->prepare(
            'SELECT r.id,r.raw_event_id,r.source_id,r.external_instrument,r.event_type,
                    r.received_at,r.sequence_value,r.data_mode,
                    r.raw_payload_json,r.transport_metadata_json,
                    p.payload_sha256,p.metadata_sha256,p.envelope_sha256
             FROM tn_capital_market_raw_events r
             LEFT JOIN tn_capital_market_raw_provenance p
               ON p.organization_id=r.organization_id AND p.raw_event_id=r.raw_event_id
             WHERE r.organization_id=:org AND r.source_id=:source AND r.id>:cursor
             ORDER BY r.id LIMIT :lim');
        $q->bindValue(':org',$org);
        $q->bindValue(':source',$source);
        $q->bindValue(':cursor',$afterId,PDO::PARAM_INT);
        $q->bindValue(':lim',$limit,PDO::PARAM_INT);
        $q->execute();
        $verified=0;$missing=0;$tampered=0;$items=[];$cursor=$afterId;
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
            $cursor=(int)$r['id'];
            $payload=json_decode((string)$r['raw_payload_json'],true,64,JSON_THROW_ON_ERROR);
            $meta=json_decode((string)$r['transport_metadata_json'],true,64,JSON_THROW_ON_ERROR);
            if(!is_array($payload)||!is_array($meta))throw new DomainException('Corrupt audited raw JSON.');
            $ph=self::jsonHash($payload);$mh=self::jsonHash($meta);
            $digest=self::envelope($org,$source,(string)$r['raw_event_id'],
                (string)$r['external_instrument'],(string)$r['event_type'],
                (string)$r['received_at'],$r['sequence_value'],
                (string)$r['data_mode'],$ph,$mh);
            $state=$r['envelope_sha256']===null?'NO_PROVENANCE':(
                hash_equals((string)$r['payload_sha256'],$ph)
                &&hash_equals((string)$r['metadata_sha256'],$mh)
                &&hash_equals((string)$r['envelope_sha256'],$digest)
                ?'VERIFIED':'TAMPERED'
            );
            if($state==='VERIFIED')$verified++;
            elseif($state==='NO_PROVENANCE')$missing++;
            else $tampered++;
            $items[]=[
                'raw_event_id'=>$r['raw_event_id'],'instrument_symbol'=>$r['external_instrument'],
                'event_type'=>$r['event_type'],'received_at'=>$r['received_at'],
                'integrity'=>$state,'sha256'=>$digest,
                'replay_mode'=>'READ_ONLY_NO_LIVE_PUBLICATION',
            ];
        }
        return ['status'=>$tampered>0?'TAMPERED':($missing>0?'INCOMPLETE':'VERIFIED'),
            'source_id'=>$source,'checked'=>count($items),'verified'=>$verified,
            'missing'=>$missing,'tampered'=>$tampered,'next_cursor'=>$cursor,
            'events'=>$items,'side_effects'=>false];
    }
}
