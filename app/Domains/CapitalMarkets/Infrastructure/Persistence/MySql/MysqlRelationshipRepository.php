<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\Persistence\MySql;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Contract\RelationshipRepository;
use Domains\CapitalMarkets\Domain\Instrument\EconomicRelationship;
use Domains\CapitalMarkets\Domain\Instrument\EconomicRelationshipStatus;
use Domains\CapitalMarkets\Domain\Instrument\EconomicRelationshipStrength;
use Domains\CapitalMarkets\Domain\Instrument\EconomicRelationshipType;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\Instrument\RelationshipId;
use PDO;

final readonly class MysqlRelationshipRepository implements RelationshipRepository
{
    public function __construct(private PDO $connection){}

    public function save(string $organizationId,EconomicRelationship $relationship):void
    {
        $statement=$this->connection->prepare(
            'INSERT INTO tn_capital_market_relationships
             (organization_id,relationship_id,source_instrument_id,target_instrument_id,relationship_type,strength,
              effective_from,effective_to,status,metadata_json)
             VALUES
             (:organization_id,:relationship_id,:source_instrument_id,:target_instrument_id,:relationship_type,:strength,
              :effective_from,:effective_to,:status,:metadata_json)
             ON DUPLICATE KEY UPDATE strength=VALUES(strength),effective_from=VALUES(effective_from),
              effective_to=VALUES(effective_to),status=VALUES(status),metadata_json=VALUES(metadata_json)'
        );
        $statement->execute([
            'organization_id'=>$organizationId,'relationship_id'=>$relationship->id->value(),
            'source_instrument_id'=>$relationship->sourceInstrument->value(),
            'target_instrument_id'=>$relationship->targetInstrument->value(),
            'relationship_type'=>$relationship->type->value,'strength'=>$relationship->strength->value,
            'effective_from'=>$relationship->effectiveFrom->format('Y-m-d H:i:s.u'),
            'effective_to'=>$relationship->effectiveTo?->format('Y-m-d H:i:s.u'),
            'status'=>$relationship->status->value,
            'metadata_json'=>json_encode($relationship->metadata,JSON_THROW_ON_ERROR),
        ]);
    }

    public function get(string $organizationId,RelationshipId $id):?EconomicRelationship
    {
        $statement=$this->connection->prepare(
            'SELECT * FROM tn_capital_market_relationships WHERE organization_id=:organization_id AND relationship_id=:relationship_id LIMIT 1'
        );
        $statement->execute(['organization_id'=>$organizationId,'relationship_id'=>$id->value()]);
        $row=$statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$this->hydrate($row):null;
    }

    public function forInstrument(string $organizationId,InstrumentId $instrumentId):array
    {
        $statement=$this->connection->prepare(
            'SELECT * FROM tn_capital_market_relationships
             WHERE organization_id=:organization_id AND (source_instrument_id=:instrument_id OR target_instrument_id=:instrument_id)
             ORDER BY relationship_type,relationship_id'
        );
        $statement->execute(['organization_id'=>$organizationId,'instrument_id'=>$instrumentId->value()]);
        return array_map(fn(array $row):EconomicRelationship=>$this->hydrate($row),$statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function list(string $organizationId,int $limit=200):array
    {
        $limit=max(1,min(500,$limit));
        $statement=$this->connection->prepare(
            'SELECT * FROM tn_capital_market_relationships WHERE organization_id=:organization_id ORDER BY updated_at DESC LIMIT '.$limit
        );
        $statement->execute(['organization_id'=>$organizationId]);
        return array_map(fn(array $row):EconomicRelationship=>$this->hydrate($row),$statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row):EconomicRelationship
    {
        $metadata=json_decode((string)$row['metadata_json'],true,flags:JSON_THROW_ON_ERROR);
        return new EconomicRelationship(
            RelationshipId::fromString((string)$row['relationship_id']),
            InstrumentId::fromString((string)$row['source_instrument_id']),
            InstrumentId::fromString((string)$row['target_instrument_id']),
            EconomicRelationshipType::from((string)$row['relationship_type']),
            EconomicRelationshipStrength::from((string)$row['strength']),
            new DateTimeImmutable((string)$row['effective_from']),
            $row['effective_to']===null?null:new DateTimeImmutable((string)$row['effective_to']),
            EconomicRelationshipStatus::from((string)$row['status']),
            is_array($metadata)?$metadata:[],
        );
    }
}
