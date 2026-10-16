<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class RawMarketEvent extends ValueObject
{
    public const MAX_RAW_PAYLOAD_BYTES=2_097_152;
    public const MAX_TRANSPORT_METADATA_BYTES=65_536;

    /**
     * @param array<string,mixed> $rawPayload
     * @param array<string,mixed> $transportMetadata
     */
    public function __construct(
        public string $eventId,
        public MarketSourceId $sourceId,
        public ?VenueId $venueId,
        public string $externalInstrument,
        public string $eventType,
        public ?DateTimeImmutable $providerTimestamp,
        public DateTimeImmutable $receivedAt,
        public ?string $sequence,
        public array $rawPayload,
        public array $transportMetadata=[],
        public MarketDataMode $mode=MarketDataMode::Live,
    ){
        if($this->eventId===''||trim($this->eventId)!==$this->eventId||mb_strlen($this->eventId)>190){
            throw new InvalidArgumentException('Raw market event id is invalid.');
        }
        if($this->externalInstrument===''||trim($this->externalInstrument)!==$this->externalInstrument||mb_strlen($this->externalInstrument)>190){
            throw new InvalidArgumentException('Raw market external instrument is invalid.');
        }
        if($this->eventType===''||trim($this->eventType)!==$this->eventType||mb_strlen($this->eventType)>100){
            throw new InvalidArgumentException('Raw market event type is invalid.');
        }
        if($this->sequence!==null&&($this->sequence===''||trim($this->sequence)!==$this->sequence||mb_strlen($this->sequence)>190)){
            throw new InvalidArgumentException('Raw market sequence is invalid.');
        }
        self::assertJsonObject($this->rawPayload,self::MAX_RAW_PAYLOAD_BYTES,'Raw market payload');
        self::assertJsonObject($this->transportMetadata,self::MAX_TRANSPORT_METADATA_BYTES,'Raw market transport metadata');
        self::assertSafeTransportMetadata($this->transportMetadata);
    }

    /**
     * Prevent accidental persistence of authentication material in the raw
     * market audit lane. Payload values remain provider market-data evidence,
     * but transport metadata must never carry credential headers or URL tokens.
     * Nested metadata is checked recursively; no secret value is logged.
     *
     * @param array<string,mixed> $metadata
     */
    private static function assertSafeTransportMetadata(array $metadata):void
    {
        $forbidden=[
            'authorization'=>true,'proxyauthorization'=>true,'cookie'=>true,
            'setcookie'=>true,'apikey'=>true,'xapikey'=>true,
            'xmbxapikey'=>true,'accesskey'=>true,'accesstoken'=>true,
            'refreshtoken'=>true,'bearertoken'=>true,'clientsecret'=>true,
            'secretkey'=>true,'privatekey'=>true,'password'=>true,
            'passwd'=>true,'credential'=>true,'credentials'=>true,
        ];
        $visit=static function(mixed $value)use(&$visit,$forbidden):void {
            if(is_array($value)){
                foreach($value as $name=>$part){
                    if(is_string($name)){
                        $normalized=strtolower(preg_replace('/[^a-z0-9]/i','',$name));
                        if(isset($forbidden[$normalized])){
                            throw new InvalidArgumentException('Raw transport metadata contains a credential field.');
                        }
                    }
                    $visit($part);
                }
                return;
            }
            if(is_string($value) && preg_match(
                '/[?&](?:access_token|api_key|apikey|authorization|client_secret|password|secret)=/i',
                $value
            )===1){
                throw new InvalidArgumentException('Raw transport URL contains a credential query parameter.');
            }
        };
        $visit($metadata);
    }

    /** @param array<string,mixed> $value */
    private static function assertJsonObject(array $value,int $maxBytes,string $label):void
    {
        if($value!==[]&&array_is_list($value))throw new InvalidArgumentException($label.' must be a JSON object.');
        $encoded=json_encode((object)$value,JSON_THROW_ON_ERROR);
        if(strlen($encoded)>$maxBytes)throw new InvalidArgumentException($label.' exceeds configured maximum size.');
    }
}
