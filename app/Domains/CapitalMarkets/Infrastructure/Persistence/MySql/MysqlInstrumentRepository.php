<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\Persistence\MySql;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Contract\InstrumentRepository;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentDescriptor;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentFamily;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentIdentifier;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentIdentifierType;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentStatus;
use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Domain\Value\Currency;
use PDO;

final readonly class MysqlInstrumentRepository implements InstrumentRepository
{
    public function __construct(private PDO $connection){}

    public function save(string $organizationId,InstrumentDescriptor $instrument,array $identifiers):void
    {
        $this->connection->beginTransaction();
        try{
            $statement=$this->connection->prepare(
                'INSERT INTO tn_capital_market_instruments
                 (organization_id,instrument_id,symbol,canonical_symbol,name,family,status,currency,quote_asset,
                  issuer_reference,jurisdiction,primary_venue_reference,metadata_json,created_at,updated_at)
                 VALUES
                 (:organization_id,:instrument_id,:symbol,:canonical_symbol,:name,:family,:status,:currency,:quote_asset,
                  :issuer_reference,:jurisdiction,:primary_venue_reference,:metadata_json,:created_at,:updated_at)
                 ON DUPLICATE KEY UPDATE
                  symbol=VALUES(symbol),canonical_symbol=VALUES(canonical_symbol),name=VALUES(name),family=VALUES(family),
                  status=VALUES(status),currency=VALUES(currency),quote_asset=VALUES(quote_asset),
                  issuer_reference=VALUES(issuer_reference),jurisdiction=VALUES(jurisdiction),
                  primary_venue_reference=VALUES(primary_venue_reference),metadata_json=VALUES(metadata_json),
                  updated_at=VALUES(updated_at)'
            );
            $statement->execute([
                'organization_id'=>$organizationId,'instrument_id'=>$instrument->id->value(),
                'symbol'=>$instrument->symbol,'canonical_symbol'=>$instrument->canonicalSymbol,'name'=>$instrument->name,
                'family'=>$instrument->family->value,'status'=>$instrument->status->value,
                'currency'=>$instrument->currency?->value(),'quote_asset'=>$instrument->quoteAsset?->value(),
                'issuer_reference'=>$instrument->issuerReference,'jurisdiction'=>$instrument->jurisdiction,
                'primary_venue_reference'=>$instrument->primaryVenueReference,
                'metadata_json'=>json_encode($instrument->metadata,JSON_THROW_ON_ERROR),
                'created_at'=>$instrument->createdAt->format('Y-m-d H:i:s.u'),
                'updated_at'=>$instrument->updatedAt->format('Y-m-d H:i:s.u'),
            ]);

            $this->connection->prepare(
                'DELETE FROM tn_capital_market_instrument_identifiers
                 WHERE organization_id=:organization_id AND instrument_id=:instrument_id'
            )->execute(['organization_id'=>$organizationId,'instrument_id'=>$instrument->id->value()]);

            $insert=$this->connection->prepare(
                'INSERT INTO tn_capital_market_instrument_identifiers
                 (organization_id,instrument_id,identifier_type,identifier_source,identifier_value)
                 VALUES (:organization_id,:instrument_id,:identifier_type,:identifier_source,:identifier_value)'
            );
            foreach($identifiers as $identifier){
                if(!$identifier instanceof InstrumentIdentifier)throw new \InvalidArgumentException('Instrument identifiers must be typed values.');
                $insert->execute([
                    'organization_id'=>$organizationId,'instrument_id'=>$instrument->id->value(),
                    'identifier_type'=>$identifier->type->value,'identifier_source'=>$identifier->source??'',
                    'identifier_value'=>$identifier->value,
                ]);
            }
            $this->connection->commit();
        }catch(\Throwable $error){
            if($this->connection->inTransaction())$this->connection->rollBack();
            throw $error;
        }
    }

    public function get(string $organizationId,InstrumentId $id):?InstrumentDescriptor
    {
        $statement=$this->connection->prepare(
            'SELECT * FROM tn_capital_market_instruments WHERE organization_id=:organization_id AND instrument_id=:instrument_id LIMIT 1'
        );
        $statement->execute(['organization_id'=>$organizationId,'instrument_id'=>$id->value()]);
        $row=$statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$this->hydrate($row):null;
    }

    public function findByIdentifier(string $organizationId,InstrumentIdentifier $identifier):?InstrumentDescriptor
    {
        $statement=$this->connection->prepare(
            'SELECT i.* FROM tn_capital_market_instrument_identifiers x
             INNER JOIN tn_capital_market_instruments i
               ON i.organization_id=x.organization_id AND i.instrument_id=x.instrument_id
             WHERE x.organization_id=:organization_id AND x.identifier_type=:identifier_type
               AND x.identifier_source=:identifier_source AND x.identifier_value=:identifier_value LIMIT 1'
        );
        $statement->execute([
            'organization_id'=>$organizationId,'identifier_type'=>$identifier->type->value,
            'identifier_source'=>$identifier->source??'','identifier_value'=>$identifier->value,
        ]);
        $row=$statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$this->hydrate($row):null;
    }

    public function list(string $organizationId,array $filters=[],int $limit=100):array
    {
        $limit=max(1,min(500,$limit));
        $where=['organization_id=:organization_id'];
        $params=['organization_id'=>$organizationId];
        if(($filters['family']??'')!==''){$where[]='family=:family';$params['family']=$filters['family'];}
        if(($filters['status']??'')!==''){$where[]='status=:status';$params['status']=$filters['status'];}
        if(($filters['q']??'')!==''){
            $where[]='(symbol LIKE :q OR canonical_symbol LIKE :q OR name LIKE :q)';
            $params['q']='%'.$filters['q'].'%';
        }
        $statement=$this->connection->prepare(
            'SELECT * FROM tn_capital_market_instruments WHERE '.implode(' AND ',$where).' ORDER BY canonical_symbol LIMIT '.$limit
        );
        $statement->execute($params);
        return array_map(fn(array $row):InstrumentDescriptor=>$this->hydrate($row),$statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function identifiers(string $organizationId,InstrumentId $id):array
    {
        $statement=$this->connection->prepare(
            'SELECT identifier_type,identifier_source,identifier_value
             FROM tn_capital_market_instrument_identifiers
             WHERE organization_id=:organization_id AND instrument_id=:instrument_id
             ORDER BY identifier_type,identifier_source,identifier_value'
        );
        $statement->execute(['organization_id'=>$organizationId,'instrument_id'=>$id->value()]);
        return array_map(static fn(array $row):InstrumentIdentifier=>new InstrumentIdentifier(
            InstrumentIdentifierType::from((string)$row['identifier_type']),
            (string)$row['identifier_value'],
            (string)$row['identifier_source']===''?null:(string)$row['identifier_source'],
        ),$statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row):InstrumentDescriptor
    {
        $metadata=json_decode((string)$row['metadata_json'],true,flags:JSON_THROW_ON_ERROR);
        return new InstrumentDescriptor(
            InstrumentId::fromString((string)$row['instrument_id']),
            (string)$row['symbol'],(string)$row['canonical_symbol'],(string)$row['name'],
            InstrumentFamily::from((string)$row['family']),InstrumentStatus::from((string)$row['status']),
            $row['currency']===null?null:new Currency((string)$row['currency']),
            $row['quote_asset']===null?null:new AssetCode((string)$row['quote_asset']),
            $row['issuer_reference']===null?null:(string)$row['issuer_reference'],
            $row['jurisdiction']===null?null:(string)$row['jurisdiction'],
            $row['primary_venue_reference']===null?null:(string)$row['primary_venue_reference'],
            is_array($metadata)?$metadata:[],
            new DateTimeImmutable((string)$row['created_at']),
            new DateTimeImmutable((string)$row['updated_at']),
        );
    }
}
