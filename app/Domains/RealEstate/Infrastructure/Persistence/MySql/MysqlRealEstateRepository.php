<?php
declare(strict_types=1);

namespace Domains\RealEstate\Infrastructure\Persistence\MySql;

use DateTimeImmutable;
use Domains\RealEstate\Application\Contract\RealEstateRepositoryInterface;
use Domains\RealEstate\Domain\BrokerageProcess;
use Domains\RealEstate\Domain\Offer;
use Domains\RealEstate\Domain\Showing;
use Kernel\Shared\Domain\OrganizationId;
use PDO;

final readonly class MysqlRealEstateRepository implements RealEstateRepositoryInterface
{
    public function __construct(private PDO $connection) {}

    public function findCase(string $organizationId, string $caseId): ?BrokerageProcess
    {
        $row = $this->one(
            'SELECT case_id,organization_id,opportunity_id,property_asset_id,inventory_id,subject,status
             FROM tn_real_estate_cases
             WHERE organization_id=:organization_id AND case_id=:case_id LIMIT 1',
            ['organization_id'=>$organizationId,'case_id'=>$caseId],
        );
        return $row === null ? null : $this->caseFromRow($row);
    }

    public function findMatch(string $organizationId, int $opportunityId, string $propertyId): ?BrokerageProcess
    {
        $row = $this->one(
            'SELECT case_id,organization_id,opportunity_id,property_asset_id,inventory_id,subject,status
             FROM tn_real_estate_cases
             WHERE organization_id=:organization_id AND opportunity_id=:opportunity_id
               AND property_asset_id=:property_asset_id LIMIT 1',
            ['organization_id'=>$organizationId,'opportunity_id'=>$opportunityId,'property_asset_id'=>$propertyId],
        );
        return $row === null ? null : $this->caseFromRow($row);
    }

    public function createCase(BrokerageProcess $case, int $actorId): bool
    {
        return $this->execCount(
            'INSERT IGNORE INTO tn_real_estate_cases
                (organization_id,case_id,opportunity_id,property_asset_id,inventory_id,subject,status,created_by,updated_by)
             VALUES
                (:organization_id,:case_id,:opportunity_id,:property_asset_id,:inventory_id,:subject,:status,:created_by,:updated_by)',
            $this->caseParams($case,$actorId),
        ) === 1;
    }

    public function saveCase(BrokerageProcess $case, int $actorId): void
    {
        $params=$this->caseParams($case,$actorId);
        $this->exec(
            'UPDATE tn_real_estate_cases SET
                inventory_id=:inventory_id,subject=:subject,status=:status,updated_by=:updated_by,updated_at=NOW()
             WHERE organization_id=:organization_id AND case_id=:case_id LIMIT 1',
            $params,
        );
    }

    public function findOffer(string $organizationId, string $offerId): ?array
    {
        return $this->one(
            'SELECT offer_id,case_id,property_asset_id,party_id,amount_minor,currency,status
             FROM tn_real_estate_offers WHERE organization_id=:organization_id AND offer_id=:offer_id LIMIT 1',
            ['organization_id'=>$organizationId,'offer_id'=>$offerId],
        );
    }

    public function createOffer(string $caseId, Offer $offer, int $actorId): bool
    {
        return $this->execCount(
            'INSERT IGNORE INTO tn_real_estate_offers
                (organization_id,offer_id,case_id,property_asset_id,party_id,amount_minor,currency,status,created_by)
             VALUES
                (:organization_id,:offer_id,:case_id,:property_asset_id,:party_id,:amount_minor,:currency,"proposed",:created_by)',
            [
                'organization_id'=>$offer->organizationId->value(),
                'offer_id'=>$offer->id,
                'case_id'=>$caseId,
                'property_asset_id'=>$offer->propertyId,
                'party_id'=>$offer->partyId,
                'amount_minor'=>$offer->amount->minorUnits(),
                'currency'=>$offer->amount->currency(),
                'created_by'=>$actorId,
            ],
        ) === 1;
    }

    public function findShowing(string $organizationId, string $showingId): ?array
    {
        return $this->one(
            'SELECT showing_id,case_id,property_asset_id,client_id,scheduled_at,status,notes
             FROM tn_real_estate_showings WHERE organization_id=:organization_id AND showing_id=:showing_id LIMIT 1',
            ['organization_id'=>$organizationId,'showing_id'=>$showingId],
        );
    }

    public function createShowing(string $caseId, Showing $showing, DateTimeImmutable $scheduledAt, ?string $notes, int $actorId): bool
    {
        return $this->execCount(
            'INSERT IGNORE INTO tn_real_estate_showings
                (organization_id,showing_id,case_id,property_asset_id,client_id,scheduled_at,status,notes,created_by)
             VALUES
                (:organization_id,:showing_id,:case_id,:property_asset_id,:client_id,:scheduled_at,"scheduled",:notes,:created_by)',
            [
                'organization_id'=>$showing->organizationId->value(),
                'showing_id'=>$showing->id,
                'case_id'=>$caseId,
                'property_asset_id'=>$showing->propertyId,
                'client_id'=>$showing->clientId,
                'scheduled_at'=>$scheduledAt->format('Y-m-d H:i:s'),
                'notes'=>$notes,
                'created_by'=>$actorId,
            ],
        ) === 1;
    }

    public function view(string $organizationId, string $caseId): ?array
    {
        $case = $this->one(
            'SELECT case_id,opportunity_id,property_asset_id,inventory_id,subject,status,created_by,updated_by,created_at,updated_at
             FROM tn_real_estate_cases WHERE organization_id=:organization_id AND case_id=:case_id LIMIT 1',
            ['organization_id'=>$organizationId,'case_id'=>$caseId],
        );
        if ($case === null) return null;

        $case['offers'] = $this->all(
            'SELECT offer_id,party_id,amount_minor,currency,status,created_by,created_at
             FROM tn_real_estate_offers
             WHERE organization_id=:organization_id AND case_id=:case_id
             ORDER BY created_at DESC,id DESC',
            ['organization_id'=>$organizationId,'case_id'=>$caseId],
        );
        $case['viewings'] = $this->all(
            'SELECT showing_id,client_id,scheduled_at,status,notes,created_by,created_at
             FROM tn_real_estate_showings
             WHERE organization_id=:organization_id AND case_id=:case_id
             ORDER BY scheduled_at DESC,id DESC',
            ['organization_id'=>$organizationId,'case_id'=>$caseId],
        );
        return $case;
    }

    private function caseFromRow(array $row): BrokerageProcess
    {
        return new BrokerageProcess(
            (string)$row['case_id'],
            OrganizationId::fromString((string)$row['organization_id']),
            (int)$row['opportunity_id'],
            (string)$row['property_asset_id'],
            isset($row['inventory_id']) && trim((string)$row['inventory_id']) !== '' ? (string)$row['inventory_id'] : null,
            (string)$row['subject'],
            (string)$row['status'],
        );
    }

    /** @return array<string,mixed> */
    private function caseParams(BrokerageProcess $case,int $actorId): array
    {
        return [
            'organization_id'=>$case->organizationId->value(),
            'case_id'=>$case->id,
            'opportunity_id'=>$case->opportunityId,
            'property_asset_id'=>$case->propertyId,
            'inventory_id'=>$case->inventoryId,
            'subject'=>$case->subject,
            'status'=>$case->status,
            'created_by'=>$actorId,
            'updated_by'=>$actorId,
        ];
    }

    private function execCount(string $sql,array $params): int
    {
        $statement=$this->connection->prepare($sql);$statement->execute($params);
        return $statement->rowCount();
    }

    private function one(string $sql, array $params): ?array
    {
        $statement=$this->connection->prepare($sql);$statement->execute($params);
        $row=$statement->fetch(PDO::FETCH_ASSOC);return $row===false?null:$row;
    }
    private function all(string $sql, array $params): array
    {
        $statement=$this->connection->prepare($sql);$statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
    private function exec(string $sql, array $params): void
    {
        $statement=$this->connection->prepare($sql);$statement->execute($params);
    }
}
