<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\Persistence\MySql;

use Domains\CapitalMarkets\Domain\Contract\VenueRepository;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentDescriptor;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Venue\VenueCapability;
use Domains\CapitalMarkets\Domain\Venue\VenueDescriptor;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use Domains\CapitalMarkets\Domain\Venue\VenueInstrument;
use Domains\CapitalMarkets\Domain\Venue\VenueInstrumentStatus;
use Domains\CapitalMarkets\Domain\Venue\VenueStatus;
use Domains\CapitalMarkets\Domain\Venue\VenueType;
use PDO;

final readonly class MysqlVenueRepository implements VenueRepository
{
    public function __construct(private PDO $connection){}

    public function save(string $organizationId,VenueDescriptor $venue,array $capabilities):void
    {
        $this->connection->beginTransaction();
        try{
            $exists=$this->connection->prepare(
                'SELECT 1 FROM tn_capital_market_venues
                 WHERE organization_id=:organization_id AND venue_id=:venue_id
                 LIMIT 1 FOR UPDATE'
            );
            $exists->execute([
                'organization_id'=>$organizationId,
                'venue_id'=>$venue->id->value(),
            ]);

            $parameters=[
                'organization_id'=>$organizationId,
                'venue_id'=>$venue->id->value(),
                'name'=>$venue->name,
                'code'=>$venue->code,
                'venue_type'=>$venue->type->value,
                'status'=>$venue->status->value,
                'jurisdiction'=>$venue->jurisdiction,
                'timezone'=>$venue->timezone,
                'base_url_reference'=>$venue->baseUrlReference,
                'metadata_json'=>json_encode((object)$venue->metadata,JSON_THROW_ON_ERROR),
            ];

            if($exists->fetchColumn()!==false){
                $statement=$this->connection->prepare(
                    'UPDATE tn_capital_market_venues
                     SET name=:name,code=:code,venue_type=:venue_type,status=:status,jurisdiction=:jurisdiction,
                         timezone=:timezone,base_url_reference=:base_url_reference,metadata_json=:metadata_json
                     WHERE organization_id=:organization_id AND venue_id=:venue_id'
                );
            }else{
                $statement=$this->connection->prepare(
                    'INSERT INTO tn_capital_market_venues
                     (organization_id,venue_id,name,code,venue_type,status,jurisdiction,timezone,base_url_reference,metadata_json)
                     VALUES (:organization_id,:venue_id,:name,:code,:venue_type,:status,:jurisdiction,:timezone,:base_url_reference,:metadata_json)'
                );
            }
            $statement->execute($parameters);

            $this->connection->prepare(
                'DELETE FROM tn_capital_market_venue_capabilities
                 WHERE organization_id=:organization_id AND venue_id=:venue_id'
            )->execute(['organization_id'=>$organizationId,'venue_id'=>$venue->id->value()]);
            $insert=$this->connection->prepare(
                'INSERT INTO tn_capital_market_venue_capabilities (organization_id,venue_id,capability)
                 VALUES (:organization_id,:venue_id,:capability)'
            );
            foreach($capabilities as $capability){
                if(!$capability instanceof VenueCapability)throw new \InvalidArgumentException('Venue capabilities must be typed values.');
                $insert->execute([
                    'organization_id'=>$organizationId,
                    'venue_id'=>$venue->id->value(),
                    'capability'=>$capability->value,
                ]);
            }
            $this->connection->commit();
        }catch(\Throwable $error){
            if($this->connection->inTransaction())$this->connection->rollBack();
            throw $error;
        }
    }

    public function get(string $organizationId,VenueId $id):?VenueDescriptor
    {
        $statement=$this->connection->prepare(
            'SELECT * FROM tn_capital_market_venues WHERE organization_id=:organization_id AND venue_id=:venue_id LIMIT 1'
        );
        $statement->execute(['organization_id'=>$organizationId,'venue_id'=>$id->value()]);
        $row=$statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$this->hydrateVenue($row):null;
    }

    public function list(string $organizationId,int $limit=100):array
    {
        $limit=max(1,min(500,$limit));
        $statement=$this->connection->prepare(
            'SELECT * FROM tn_capital_market_venues WHERE organization_id=:organization_id ORDER BY name LIMIT '.$limit
        );
        $statement->execute(['organization_id'=>$organizationId]);
        return array_map(fn(array $row):VenueDescriptor=>$this->hydrateVenue($row),$statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function capabilities(string $organizationId,VenueId $id):array
    {
        $statement=$this->connection->prepare(
            'SELECT capability FROM tn_capital_market_venue_capabilities
             WHERE organization_id=:organization_id AND venue_id=:venue_id ORDER BY capability'
        );
        $statement->execute(['organization_id'=>$organizationId,'venue_id'=>$id->value()]);
        return array_map(static fn(array $row):VenueCapability=>VenueCapability::from((string)$row['capability']),$statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function registerInstrument(string $organizationId,VenueInstrument $mapping):void
    {
        $statement=$this->connection->prepare(
            'INSERT INTO tn_capital_market_venue_instruments
             (organization_id,venue_id,instrument_id,venue_symbol,status,price_precision,quantity_precision,
              minimum_quantity,minimum_notional,metadata_json)
             VALUES
             (:organization_id,:venue_id,:instrument_id,:venue_symbol,:status,:price_precision,:quantity_precision,
              :minimum_quantity,:minimum_notional,:metadata_json)'
        );
        $statement->execute([
            'organization_id'=>$organizationId,'venue_id'=>$mapping->venueId->value(),'instrument_id'=>$mapping->instrumentId->value(),
            'venue_symbol'=>$mapping->venueSymbol,'status'=>$mapping->status->value,'price_precision'=>$mapping->pricePrecision,
            'quantity_precision'=>$mapping->quantityPrecision,'minimum_quantity'=>$mapping->minimumQuantity?->value(),
            'minimum_notional'=>$mapping->minimumNotional?->value(),
            'metadata_json'=>json_encode((object)$mapping->metadata,JSON_THROW_ON_ERROR),
        ]);
    }

    public function instruments(string $organizationId,VenueId $venueId):array
    {
        $statement=$this->connection->prepare(
            'SELECT * FROM tn_capital_market_venue_instruments WHERE organization_id=:organization_id AND venue_id=:venue_id ORDER BY venue_symbol'
        );
        $statement->execute(['organization_id'=>$organizationId,'venue_id'=>$venueId->value()]);
        return array_map(fn(array $row):VenueInstrument=>$this->hydrateMapping($row),$statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function venuesForInstrument(string $organizationId,InstrumentId $instrumentId):array
    {
        $statement=$this->connection->prepare(
            'SELECT * FROM tn_capital_market_venue_instruments WHERE organization_id=:organization_id AND instrument_id=:instrument_id ORDER BY venue_id'
        );
        $statement->execute(['organization_id'=>$organizationId,'instrument_id'=>$instrumentId->value()]);
        return array_map(fn(array $row):VenueInstrument=>$this->hydrateMapping($row),$statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<string,mixed> $row */
    private function hydrateVenue(array $row):VenueDescriptor
    {
        $metadata=json_decode((string)$row['metadata_json'],true,flags:JSON_THROW_ON_ERROR);
        return new VenueDescriptor(
            VenueId::fromString((string)$row['venue_id']),(string)$row['name'],(string)$row['code'],
            VenueType::from((string)$row['venue_type']),VenueStatus::from((string)$row['status']),
            $row['jurisdiction']===null?null:(string)$row['jurisdiction'],(string)$row['timezone'],
            $row['base_url_reference']===null?null:(string)$row['base_url_reference'],
            is_array($metadata)?$metadata:[],
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrateMapping(array $row):VenueInstrument
    {
        $metadata=json_decode((string)$row['metadata_json'],true,flags:JSON_THROW_ON_ERROR);
        return new VenueInstrument(
            VenueId::fromString((string)$row['venue_id']),InstrumentId::fromString((string)$row['instrument_id']),
            (string)$row['venue_symbol'],VenueInstrumentStatus::from((string)$row['status']),
            (int)$row['price_precision'],(int)$row['quantity_precision'],
            $row['minimum_quantity']===null?null:Decimal::fromString((string)$row['minimum_quantity']),
            $row['minimum_notional']===null?null:Decimal::fromString((string)$row['minimum_notional']),
            is_array($metadata)?$metadata:[],
        );
    }
}
