<?php
declare(strict_types=1);

namespace Domains\Property\Infrastructure\Persistence\MySql;

use Domains\Property\Application\Contract\PropertyIdentityWorkflowRepositoryInterface;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final readonly class MysqlPropertyIdentityWorkflowRepository implements PropertyIdentityWorkflowRepositoryInterface
{
    public function __construct(private PDO $connection) {}

    public function context(string $organizationId, int $submissionId, int $legacyPropertyId): ?array
    {
        $statement = $this->connection->prepare('SELECT
                s.id AS submission_id,s.submission_ref,s.property_type,s.title AS submission_title,
                s.city AS submission_city,s.region AS submission_region,s.district AS submission_district,
                s.address AS submission_address,s.area_total AS submission_total_area,s.land_area AS submission_land_area,
                s.rooms AS submission_rooms,s.floor AS submission_floor,s.floors AS submission_floors,
                s.built_year AS submission_built_year,
                p.id AS legacy_property_id,p.address AS legacy_address,p.area_total AS legacy_total_area,
                p.land_area AS legacy_land_area,p.rooms AS legacy_rooms,p.floor AS legacy_floor,
                p.floors AS legacy_floors,p.built_year AS legacy_built_year,
                t.code AS legacy_type_code,l.id AS legacy_location_id,l.city,l.region
            FROM tn_property_submissions s
            INNER JOIN tn_properties p
              ON p.id=:legacy_property_id
             AND p.organization_id=s.organization_id
            LEFT JOIN tn_property_types t ON t.id=p.type_id
            LEFT JOIN tn_locations l ON l.id=p.location_id
            WHERE s.id=:submission_id
              AND s.organization_id=:organization_id
              AND s.property_id=:legacy_property_id_for_submission
            LIMIT 1');
        $statement->execute([
            'organization_id' => $organizationId,
            'submission_id' => $submissionId,
            'legacy_property_id' => $legacyPropertyId,
            'legacy_property_id_for_submission' => $legacyPropertyId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;

        $row['type_code'] = $this->firstString($row['property_type'] ?? null, $row['legacy_type_code'] ?? null, 'property');
        $row['address'] = $this->firstString($row['submission_address'] ?? null, $row['legacy_address'] ?? null);
        $row['total_area'] = $this->firstNumber($row['submission_total_area'] ?? null, $row['legacy_total_area'] ?? null);
        $row['land_area'] = $this->firstNumber($row['submission_land_area'] ?? null, $row['legacy_land_area'] ?? null);
        $row['rooms'] = $this->firstNumber($row['submission_rooms'] ?? null, $row['legacy_rooms'] ?? null);
        $row['floor'] = $this->firstInteger($row['submission_floor'] ?? null, $row['legacy_floor'] ?? null);
        $row['floors'] = $this->firstInteger($row['submission_floors'] ?? null, $row['legacy_floors'] ?? null);
        $row['built_year'] = $this->firstInteger($row['submission_built_year'] ?? null, $row['legacy_built_year'] ?? null);
        $row['city'] = $this->firstString($row['submission_city'] ?? null, $row['city'] ?? null);
        $row['region'] = $this->firstString($row['submission_region'] ?? null, $row['region'] ?? null);
        $row['address_canonical_key'] = $this->addressKey($row['region'] ?? null, $row['city'] ?? null, $row['address'] ?? null);
        $row['external_reference_keys'] = $this->externalReferenceKeys($organizationId, $submissionId);
        $row['cadastral_number'] = null;
        $row['development_asset_id'] = null;
        $row['building_asset_id'] = null;
        $row['unit_label'] = null;

        return $row;
    }

    public function candidates(string $organizationId, string $typeCode, int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $params = ['organization_id' => $organizationId];
        $typeClause = '';
        if (trim($typeCode) !== '') {
            $typeClause = ' AND a.type_code=:type_code';
            $params['type_code'] = trim($typeCode);
        }

        $statement = $this->connection->prepare('SELECT
                a.asset_id,a.type_code,a.kind,a.lifecycle,a.legacy_property_id,
                address.formatted_address,address.unit_label,
                location.canonical_key AS location_canonical_key,location.name AS location_name,
                COALESCE(rs.total_area,cs.total_area,bs.gross_area) AS total_area,
                GROUP_CONCAT(DISTINCT CONCAT(er.source_system, ":", er.external_id) ORDER BY er.source_system,er.external_id SEPARATOR ",") AS external_reference_keys
            FROM tn_property_assets a
            LEFT JOIN tn_addresses address ON address.id=a.address_id
            LEFT JOIN tn_location_nodes location ON location.id=COALESCE(a.location_node_id,address.locality_node_id)
            LEFT JOIN tn_property_residential_specs rs ON rs.organization_id=a.organization_id AND rs.asset_id=a.asset_id
            LEFT JOIN tn_property_commercial_specs cs ON cs.organization_id=a.organization_id AND cs.asset_id=a.asset_id
            LEFT JOIN tn_property_building_specs bs ON bs.organization_id=a.organization_id AND bs.asset_id=a.asset_id
            LEFT JOIN tn_property_external_references er ON er.organization_id=a.organization_id AND er.asset_id=a.asset_id
            WHERE a.organization_id=:organization_id' . $typeClause . '
            GROUP BY a.id
            ORDER BY a.updated_at DESC,a.asset_id
            LIMIT ' . $limit);
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['address_canonical_key'] = $this->addressKey(null, $row['location_name'] ?? null, $row['formatted_address'] ?? null);
            $row['development_asset_id'] = null;
            $row['building_asset_id'] = null;
            $row['cadastral_number'] = null;
        }
        unset($row);

        return $rows;
    }

    public function canonicalForLegacy(string $organizationId, int $legacyPropertyId): ?string
    {
        if ($legacyPropertyId <= 0) return null;

        $statement = $this->connection->prepare('SELECT asset_id
            FROM tn_property_asset_legacy_links
            WHERE organization_id=:organization_id AND legacy_property_id=:legacy_property_id
            LIMIT 1');
        $statement->execute(['organization_id' => $organizationId, 'legacy_property_id' => $legacyPropertyId]);
        $assetId = $statement->fetchColumn();
        if (is_string($assetId) && $assetId !== '') return $assetId;

        $statement = $this->connection->prepare('SELECT asset_id
            FROM tn_property_assets
            WHERE organization_id=:organization_id AND legacy_property_id=:legacy_property_id
            LIMIT 1');
        $statement->execute(['organization_id' => $organizationId, 'legacy_property_id' => $legacyPropertyId]);
        $assetId = $statement->fetchColumn();
        return is_string($assetId) && $assetId !== '' ? $assetId : null;
    }

    public function recordResolution(
        string $organizationId,
        int $submissionId,
        ?string $candidateAssetId,
        ?string $resolvedAssetId,
        string $decision,
        float $score,
        array $signals,
        array $reasons,
        string $reviewStatus,
    ): int {
        $statement = $this->connection->prepare('INSERT INTO tn_property_identity_resolutions (
                organization_id,submission_id,candidate_asset_id,resolved_asset_id,decision,score,
                signals_json,reason,review_status,resolved_at
            ) VALUES (
                :organization_id,:submission_id,:candidate_asset_id,:resolved_asset_id,:decision,:score,
                :signals_json,:reason,:review_status,:resolved_at
            )
            ON DUPLICATE KEY UPDATE
                id=LAST_INSERT_ID(id),
                candidate_asset_id=VALUES(candidate_asset_id),
                resolved_asset_id=VALUES(resolved_asset_id),
                decision=VALUES(decision),
                score=VALUES(score),
                signals_json=VALUES(signals_json),
                reason=VALUES(reason),
                review_status=VALUES(review_status),
                resolved_at=VALUES(resolved_at),
                updated_at=NOW()');
        $statement->execute([
            'organization_id' => $organizationId,
            'submission_id' => $submissionId,
            'candidate_asset_id' => $candidateAssetId,
            'resolved_asset_id' => $resolvedAssetId,
            'decision' => strtolower(trim($decision)),
            'score' => max(0.0, min(1.0, $score)),
            'signals_json' => json_encode($signals, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
            'reason' => $this->nullable(mb_substr(implode(',', array_map('strval', $reasons)), 0, 255)),
            'review_status' => $reviewStatus,
            'resolved_at' => $reviewStatus === 'resolved' ? date('Y-m-d H:i:s') : null,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function materialize(
        string $organizationId,
        int $submissionId,
        int $legacyPropertyId,
        array $context,
        ?string $targetAssetId = null,
    ): string {
        $existing = $this->canonicalForLegacy($organizationId, $legacyPropertyId);
        if ($existing !== null) return $existing;

        $pdo = $this->connection;
        try {
            $pdo->beginTransaction();

            $assetId = trim((string) $targetAssetId);
            $linkType = 'alias';
            if ($assetId !== '') {
                $asset = $pdo->prepare('SELECT asset_id FROM tn_property_assets
                    WHERE organization_id=:organization_id AND asset_id=:asset_id LIMIT 1 FOR UPDATE');
                $asset->execute(['organization_id' => $organizationId, 'asset_id' => $assetId]);
                if (!$asset->fetchColumn()) throw new InvalidArgumentException('Target canonical Property asset was not found.');
            } else {
                $assetId = $this->newAssetId($pdo, $organizationId);
                $linkType = 'primary';
                $locationNodeId = $this->resolveLocationNode($pdo, $context);
                $addressId = $this->createAddress($pdo, $locationNodeId, $context);
                $typeCode = mb_substr($this->firstString($context['type_code'] ?? null, 'property') ?? 'property', 0, 50);
                $kind = $this->kind($typeCode);

                $insert = $pdo->prepare('INSERT INTO tn_property_assets (
                        organization_id,asset_id,kind,type_code,lifecycle,location_node_id,address_id,legacy_property_id
                    ) VALUES (
                        :organization_id,:asset_id,:kind,:type_code,"unknown",:location_node_id,:address_id,:legacy_property_id
                    )');
                $insert->execute([
                    'organization_id' => $organizationId,
                    'asset_id' => $assetId,
                    'kind' => $kind,
                    'type_code' => $typeCode,
                    'location_node_id' => $locationNodeId,
                    'address_id' => $addressId,
                    'legacy_property_id' => $legacyPropertyId,
                ]);
                $this->writeSpecs($pdo, $organizationId, $assetId, $kind, $typeCode, $context);
            }

            $link = $pdo->prepare('INSERT INTO tn_property_asset_legacy_links (
                    organization_id,legacy_property_id,asset_id,link_type
                ) VALUES (:organization_id,:legacy_property_id,:asset_id,:link_type)
                ON DUPLICATE KEY UPDATE asset_id=VALUES(asset_id),link_type=VALUES(link_type)');
            $link->execute([
                'organization_id' => $organizationId,
                'legacy_property_id' => $legacyPropertyId,
                'asset_id' => $assetId,
                'link_type' => $linkType,
            ]);

            $this->attachExternalReferences($pdo, $organizationId, $submissionId, $assetId);
            $network = $pdo->prepare('UPDATE tn_property_network_records
                SET asset_id=:asset_id
                WHERE organization_id=:organization_id AND submission_id=:submission_id');
            $network->execute(['asset_id' => $assetId, 'organization_id' => $organizationId, 'submission_id' => $submissionId]);

            $pdo->commit();
            return $assetId;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    public function pendingReviews(string $organizationId, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $statement = $this->connection->prepare('SELECT
                r.id,r.submission_id,r.candidate_asset_id,r.resolved_asset_id,r.decision,r.score,
                r.signals_json,r.reason,r.review_status,r.created_at,r.updated_at,
                s.submission_ref,s.title,s.property_type,s.city,s.address,s.property_id AS legacy_property_id,
                a.type_code AS candidate_type_code,a.lifecycle AS candidate_lifecycle
            FROM tn_property_identity_resolutions r
            LEFT JOIN tn_property_submissions s ON s.organization_id=r.organization_id AND s.id=r.submission_id
            LEFT JOIN tn_property_assets a ON a.organization_id=r.organization_id AND a.asset_id=r.candidate_asset_id
            WHERE r.organization_id=:organization_id AND r.review_status="pending"
            ORDER BY r.score DESC,r.created_at,r.id
            LIMIT ' . $limit);
        $statement->execute(['organization_id' => $organizationId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function resolution(string $organizationId, int $resolutionId): ?array
    {
        $statement = $this->connection->prepare('SELECT r.*,s.property_id AS legacy_property_id,s.submission_ref
            FROM tn_property_identity_resolutions r
            LEFT JOIN tn_property_submissions s ON s.organization_id=r.organization_id AND s.id=r.submission_id
            WHERE r.organization_id=:organization_id AND r.id=:resolution_id
            LIMIT 1');
        $statement->execute(['organization_id' => $organizationId, 'resolution_id' => $resolutionId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function completeReview(
        string $organizationId,
        int $resolutionId,
        string $decision,
        string $assetId,
        ?string $reviewerReference,
        ?string $note,
    ): void {
        $pdo = $this->connection;
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT id,review_status FROM tn_property_identity_resolutions
                WHERE organization_id=:organization_id AND id=:resolution_id LIMIT 1 FOR UPDATE');
            $lock->execute(['organization_id' => $organizationId, 'resolution_id' => $resolutionId]);
            $row = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$row || (string) $row['review_status'] !== 'pending') {
                throw new RuntimeException('Pending Property identity review is no longer available.');
            }

            $update = $pdo->prepare('UPDATE tn_property_identity_resolutions
                SET decision=:decision,resolved_asset_id=:resolved_asset_id,review_status="resolved",
                    reviewer_reference=:reviewer_reference,review_note=:review_note,resolved_at=NOW(),updated_at=NOW()
                WHERE organization_id=:organization_id AND id=:resolution_id LIMIT 1');
            $update->execute([
                'decision' => strtolower(trim($decision)),
                'resolved_asset_id' => $assetId,
                'reviewer_reference' => $this->nullable($reviewerReference),
                'review_note' => $this->nullable($note),
                'organization_id' => $organizationId,
                'resolution_id' => $resolutionId,
            ]);

            $audit = $pdo->prepare('INSERT INTO tn_property_identity_review_audit (
                    organization_id,resolution_id,action,resolved_asset_id,reviewer_reference,note
                ) VALUES (
                    :organization_id,:resolution_id,:action,:resolved_asset_id,:reviewer_reference,:note
                )');
            $audit->execute([
                'organization_id' => $organizationId,
                'resolution_id' => $resolutionId,
                'action' => strtolower(trim($decision)),
                'resolved_asset_id' => $assetId,
                'reviewer_reference' => $this->nullable($reviewerReference),
                'note' => $this->nullable($note),
            ]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    /** @return list<string> */
    private function externalReferenceKeys(string $organizationId, int $submissionId): array
    {
        $statement = $this->connection->prepare('SELECT DISTINCT COALESCE(s.source_system,n.source_id) AS source_system,n.external_id
            FROM tn_property_network_records n
            LEFT JOIN tn_property_sources s ON s.organization_id=n.organization_id AND s.source_id=n.source_id
            WHERE n.organization_id=:organization_id AND n.submission_id=:submission_id
            ORDER BY source_system,n.external_id');
        $statement->execute(['organization_id' => $organizationId, 'submission_id' => $submissionId]);
        $keys = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $system = trim((string) ($row['source_system'] ?? ''));
            $external = trim((string) ($row['external_id'] ?? ''));
            if ($system !== '' && $external !== '') $keys[] = $system . ':' . $external;
        }
        return array_values(array_unique($keys));
    }

    private function attachExternalReferences(PDO $pdo, string $organizationId, int $submissionId, string $assetId): void
    {
        $select = $pdo->prepare('SELECT DISTINCT n.source_id,COALESCE(s.source_system,n.source_id) AS source_system,n.external_id
            FROM tn_property_network_records n
            LEFT JOIN tn_property_sources s ON s.organization_id=n.organization_id AND s.source_id=n.source_id
            WHERE n.organization_id=:organization_id AND n.submission_id=:submission_id');
        $select->execute(['organization_id' => $organizationId, 'submission_id' => $submissionId]);
        $insert = $pdo->prepare('INSERT INTO tn_property_external_references (
                organization_id,asset_id,source_id,source_system,external_id
            ) VALUES (
                :organization_id,:asset_id,:source_id,:source_system,:external_id
            )
            ON DUPLICATE KEY UPDATE asset_id=VALUES(asset_id),source_id=VALUES(source_id),imported_at=CURRENT_TIMESTAMP');
        foreach ($select->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (trim((string) ($row['source_id'] ?? '')) === '' || trim((string) ($row['external_id'] ?? '')) === '') continue;
            $insert->execute([
                'organization_id' => $organizationId,
                'asset_id' => $assetId,
                'source_id' => $row['source_id'],
                'source_system' => $row['source_system'],
                'external_id' => $row['external_id'],
            ]);
        }
    }

    private function resolveLocationNode(PDO $pdo, array $context): ?int
    {
        $legacyLocationId = (int) ($context['legacy_location_id'] ?? 0);
        $city = $this->firstString($context['city'] ?? null, $context['submission_city'] ?? null);
        if ($legacyLocationId <= 0 && $city === null) return null;

        $canonicalKey = $legacyLocationId > 0
            ? 'legacy-location:' . $legacyLocationId
            : 'observed-city:' . substr(hash('sha256', strtolower(trim((string) $city))), 0, 32);
        $name = mb_substr($city ?? 'Unknown location', 0, 160);

        $insert = $pdo->prepare('INSERT INTO tn_location_nodes (node_id,node_type,canonical_key,name,country_code)
            VALUES (:node_id,"city",:canonical_key,:name,"UA")
            ON DUPLICATE KEY UPDATE name=VALUES(name),updated_at=NOW()');
        $insert->execute([
            'node_id' => 'LOC-' . substr(hash('sha256', $canonicalKey), 0, 24),
            'canonical_key' => $canonicalKey,
            'name' => $name,
        ]);
        $select = $pdo->prepare('SELECT id FROM tn_location_nodes WHERE canonical_key=:canonical_key LIMIT 1');
        $select->execute(['canonical_key' => $canonicalKey]);
        $id = $select->fetchColumn();
        return $id ? (int) $id : null;
    }

    private function createAddress(PDO $pdo, ?int $locationNodeId, array $context): ?int
    {
        $formatted = $this->firstString($context['address'] ?? null, $context['submission_address'] ?? null, $context['legacy_address'] ?? null);
        if ($locationNodeId === null || $formatted === null) return null;

        $insert = $pdo->prepare('INSERT INTO tn_addresses (locality_node_id,formatted_address)
            VALUES (:locality_node_id,:formatted_address)');
        $insert->execute(['locality_node_id' => $locationNodeId, 'formatted_address' => mb_substr($formatted, 0, 255)]);
        return (int) $pdo->lastInsertId();
    }

    private function writeSpecs(PDO $pdo, string $organizationId, string $assetId, string $kind, string $typeCode, array $context): void
    {
        $totalArea = $this->firstNumber($context['total_area'] ?? null, $context['legacy_total_area'] ?? null);
        $landArea = $this->firstNumber($context['land_area'] ?? null, $context['legacy_land_area'] ?? null);
        $rooms = $this->firstNumber($context['rooms'] ?? null, $context['legacy_rooms'] ?? null);
        $floors = $this->firstInteger($context['floors'] ?? null, $context['legacy_floors'] ?? null);
        $builtYear = $this->firstInteger($context['built_year'] ?? null, $context['legacy_built_year'] ?? null);

        $commercial = in_array(strtolower($typeCode), ['commercial','commercial_unit','office','retail','warehouse'], true);
        if ($commercial) {
            $statement = $pdo->prepare('INSERT INTO tn_property_commercial_specs (organization_id,asset_id,total_area)
                VALUES (:organization_id,:asset_id,:total_area)
                ON DUPLICATE KEY UPDATE total_area=VALUES(total_area)');
            $statement->execute(['organization_id' => $organizationId, 'asset_id' => $assetId, 'total_area' => $totalArea]);
            return;
        }
        if ($kind === 'land_plot') {
            $statement = $pdo->prepare('INSERT INTO tn_property_land_specs (organization_id,asset_id,land_area)
                VALUES (:organization_id,:asset_id,:land_area)
                ON DUPLICATE KEY UPDATE land_area=VALUES(land_area)');
            $statement->execute(['organization_id' => $organizationId, 'asset_id' => $assetId, 'land_area' => $landArea]);
            return;
        }
        if (in_array($kind, ['building','development'], true)) {
            $statement = $pdo->prepare('INSERT INTO tn_property_building_specs (organization_id,asset_id,gross_area,floors,built_year)
                VALUES (:organization_id,:asset_id,:gross_area,:floors,:built_year)
                ON DUPLICATE KEY UPDATE gross_area=VALUES(gross_area),floors=VALUES(floors),built_year=VALUES(built_year)');
            $statement->execute(['organization_id' => $organizationId, 'asset_id' => $assetId, 'gross_area' => $totalArea, 'floors' => $floors, 'built_year' => $builtYear]);
            return;
        }

        $statement = $pdo->prepare('INSERT INTO tn_property_residential_specs (organization_id,asset_id,total_area,rooms)
            VALUES (:organization_id,:asset_id,:total_area,:rooms)
            ON DUPLICATE KEY UPDATE total_area=VALUES(total_area),rooms=VALUES(rooms)');
        $statement->execute(['organization_id' => $organizationId, 'asset_id' => $assetId, 'total_area' => $totalArea, 'rooms' => $rooms]);
    }

    private function newAssetId(PDO $pdo, string $organizationId): string
    {
        $select = $pdo->prepare('SELECT 1 FROM tn_property_assets WHERE organization_id=:organization_id AND asset_id=:asset_id LIMIT 1');
        do {
            $assetId = 'PROP-' . strtoupper(bin2hex(random_bytes(10)));
            $select->execute(['organization_id' => $organizationId, 'asset_id' => $assetId]);
        } while ($select->fetchColumn());
        return $assetId;
    }

    private function kind(string $typeCode): string
    {
        return match (strtolower(trim($typeCode))) {
            'development','residential_complex','complex' => 'development',
            'land','land_plot','plot' => 'land_plot',
            'building' => 'building',
            'section' => 'section',
            'entrance' => 'entrance',
            'floor' => 'floor',
            'house','cottage','townhouse','villa' => 'house',
            default => 'unit',
        };
    }

    private function addressKey(mixed $region, mixed $city, mixed $address): ?string
    {
        $parts = [];
        foreach ([$region, $city, $address] as $value) {
            $value = mb_strtolower(trim((string) $value));
            if ($value !== '') $parts[] = preg_replace('/\s+/u', ' ', $value) ?: $value;
        }
        return $parts === [] ? null : implode('|', $parts);
    }

    private function firstString(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '') return $value;
        }
        return null;
    }

    private function firstNumber(mixed ...$values): ?float
    {
        foreach ($values as $value) if ($value !== null && $value !== '' && is_numeric($value)) return (float) $value;
        return null;
    }

    private function firstInteger(mixed ...$values): ?int
    {
        foreach ($values as $value) if ($value !== null && $value !== '' && is_numeric($value)) return (int) $value;
        return null;
    }

    private function nullable(?string $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
