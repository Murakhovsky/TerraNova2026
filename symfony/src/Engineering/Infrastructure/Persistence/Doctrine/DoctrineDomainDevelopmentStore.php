<?php
declare(strict_types=1);

namespace App\Engineering\Infrastructure\Persistence\Doctrine;

use App\Engineering\Domain\DomainDevelopment\DomainDevelopmentStatus;
use App\Engineering\Domain\DomainDevelopment\DomainFeatureStatus;
use App\Engineering\Domain\Workflow\EngineeringId;
use Doctrine\DBAL\Connection;
use RuntimeException;

final readonly class DoctrineDomainDevelopmentStore
{
    public function __construct(private Connection $connection) {}

    public function createDomain(
        string $id,
        string $organizationId,
        string $domainKey,
        string $name,
        string $description,
        array $masterSpecification,
        string $version,
        string $repository,
        string $targetBranch,
        ?string $createdBy,
    ): void {
        $this->connection->insert('cos_engineering_domains', [
            'id' => $id,
            'organization_id' => $organizationId,
            'domain_key' => $domainKey,
            'name' => $name,
            'description' => $description,
            'status' => DomainDevelopmentStatus::DRAFT->value,
            'version' => $version,
            'master_specification' => $this->json($masterSpecification),
            'business_goal' => (string) ($masterSpecification['business_goal'] ?? ''),
            'scope_json' => $this->json(is_array($masterSpecification['scope'] ?? null) ? $masterSpecification['scope'] : []),
            'out_of_scope_json' => $this->json(is_array($masterSpecification['out_of_scope'] ?? null) ? $masterSpecification['out_of_scope'] : []),
            'architecture_json' => null,
            'constitution_json' => null,
            'domain_acceptance_criteria' => $this->json(is_array($masterSpecification['acceptance_criteria'] ?? null) ? $masterSpecification['acceptance_criteria'] : []),
            'architecture_version' => 0,
            'qa_status' => 'NOT_RUN',
            'release_status' => 'NOT_READY',
            'target_repository' => $repository,
            'target_branch' => $targetBranch,
            'created_by' => $createdBy,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
        ]);
        $this->audit($id, 'DOMAIN_CREATED', ['domain_key' => $domainKey, 'version' => $version], $createdBy);
    }

    /** @return array<string,mixed> */
    public function domain(string $id): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM cos_engineering_domains WHERE id = :id', ['id' => $id]);
        if ($row === false) throw new RuntimeException('Engineering domain not found: '.$id);
        return $this->decodeDomain($row);
    }

    /** @return list<array<string,mixed>> */
    public function domainsForOrganization(string $organizationId, int $limit = 100): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM cos_engineering_domains WHERE organization_id = :organization_id ORDER BY updated_at DESC LIMIT '.max(1, min(500, $limit)),
            ['organization_id' => $organizationId],
        );
        return array_map(fn (array $row): array => $this->decodeDomain($row), $rows);
    }

    public function updateDomainStatus(string $domainId, DomainDevelopmentStatus $status, ?string $actor = null, ?string $reason = null): void
    {
        $this->connection->update('cos_engineering_domains', [
            'status' => $status->value,
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
        ], ['id' => $domainId]);
        $this->audit($domainId, 'DOMAIN_STATUS_CHANGED', ['status' => $status->value, 'reason' => $reason], $actor);
    }

    public function setArchitecture(
        string $domainId,
        array $architecture,
        array $constitution,
        array $domainAcceptanceCriteria,
        ?string $actor = null,
    ): int {
        $current = $this->domain($domainId);
        $version = (int) ($current['architecture_version'] ?? 0) + 1;
        $this->connection->update('cos_engineering_domains', [
            'architecture_json' => $this->json($architecture),
            'constitution_json' => $this->json($constitution),
            'domain_acceptance_criteria' => $this->json($domainAcceptanceCriteria),
            'architecture_version' => $version,
            'status' => DomainDevelopmentStatus::READY_FOR_IMPLEMENTATION->value,
            'qa_status' => 'NOT_RUN',
            'release_status' => 'NOT_READY',
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
        ], ['id' => $domainId]);

        if ($version > 1) {
            $this->connection->executeStatement(
                "UPDATE cos_engineering_domain_features
                 SET status = CASE
                     WHEN status = 'COMPLETED' THEN 'STALE'
                     WHEN status IN ('NOT_STARTED','READY','BLOCKED','FAILED') THEN 'REVALIDATION_REQUIRED'
                     ELSE status
                 END,
                 updated_at = UTC_TIMESTAMP(6)
                 WHERE domain_id = :domain_id AND architecture_version < :version",
                ['domain_id' => $domainId, 'version' => $version],
            );
        }

        $this->createArtifact($domainId, 'DOMAIN_ARCHITECTURE', $architecture, $version, 'PRINCIPAL_ARCHITECT');
        $this->createArtifact($domainId, 'DOMAIN_ARCHITECTURE_CONSTITUTION', $constitution, $version, 'PRINCIPAL_ARCHITECT');
        $this->audit($domainId, 'DOMAIN_ARCHITECTURE_APPROVED', ['architecture_version' => $version], $actor);
        return $version;
    }

    public function addCapability(
        string $id,
        string $domainId,
        string $key,
        string $name,
        string $description,
        string $kind,
        bool $required = true,
    ): void {
        $this->connection->insert('cos_engineering_domain_capabilities', [
            'id' => $id,
            'domain_id' => $domainId,
            'capability_key' => $key,
            'name' => $name,
            'description' => $description,
            'kind' => $kind,
            'status' => 'NOT_STARTED',
            'required' => $required ? 1 : 0,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
        ]);
        $this->audit($domainId, 'CAPABILITY_CREATED', ['capability_id' => $id, 'capability_key' => $key]);
    }

    /** @return list<array<string,mixed>> */
    public function capabilities(string $domainId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM cos_engineering_domain_capabilities WHERE domain_id = :domain_id ORDER BY capability_key',
            ['domain_id' => $domainId],
        );
        return array_map(static function (array $row): array {
            $row['required'] = (bool) ($row['required'] ?? false);
            return $row;
        }, $rows);
    }

    public function addFeature(
        string $id,
        string $domainId,
        ?string $capabilityId,
        string $featureKey,
        string $title,
        string $description,
        string $kind,
        string $risk,
        string $priority,
        bool $required,
        array $ownedPaths,
        array $sharedPaths,
        array $forbiddenPaths,
    ): void {
        $domain = $this->domain($domainId);
        $this->connection->insert('cos_engineering_domain_features', [
            'id' => $id,
            'domain_id' => $domainId,
            'capability_id' => $capabilityId,
            'feature_key' => $featureKey,
            'title' => $title,
            'description' => $description,
            'kind' => $kind,
            'risk' => $risk,
            'priority' => $priority,
            'required' => $required ? 1 : 0,
            'status' => DomainFeatureStatus::NOT_STARTED->value,
            'engineering_feature_id' => null,
            'architecture_version' => (int) ($domain['architecture_version'] ?? 0),
            'owned_paths' => $this->json($ownedPaths),
            'shared_paths' => $this->json($sharedPaths),
            'forbidden_paths' => $this->json($forbiddenPaths),
            'last_error' => null,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
        ]);
        $this->audit($domainId, 'FEATURE_CREATED', ['feature_id' => $id, 'feature_key' => $featureKey, 'kind' => $kind]);
    }

    public function addDependency(
        string $id,
        string $domainId,
        string $featureId,
        string $dependsOnFeatureId,
        string $type,
    ): void {
        $this->connection->insert('cos_engineering_domain_dependencies', [
            'id' => $id,
            'domain_id' => $domainId,
            'feature_id' => $featureId,
            'depends_on_feature_id' => $dependsOnFeatureId,
            'dependency_type' => $type,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
        ]);
        $this->audit($domainId, 'FEATURE_DEPENDENCY_CREATED', [
            'feature_id' => $featureId,
            'depends_on_feature_id' => $dependsOnFeatureId,
            'dependency_type' => $type,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function features(string $domainId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM cos_engineering_domain_features WHERE domain_id = :domain_id ORDER BY FIELD(priority, "P0","P1","P2","P3"), feature_key',
            ['domain_id' => $domainId],
        );
        return array_map(fn (array $row): array => $this->decodeFeature($row), $rows);
    }

    /** @return array<string,mixed> */
    public function feature(string $featureId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM cos_engineering_domain_features WHERE id = :id',
            ['id' => $featureId],
        );
        if ($row === false) throw new RuntimeException('Engineering domain feature not found: '.$featureId);
        return $this->decodeFeature($row);
    }

    /** @return list<array<string,mixed>> */
    public function dependencies(string $domainId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM cos_engineering_domain_dependencies WHERE domain_id = :domain_id ORDER BY created_at, id',
            ['domain_id' => $domainId],
        );
    }

    public function claimFeature(string $featureId, int $architectureVersion): bool
    {
        return 1 === $this->connection->executeStatement(
            "UPDATE cos_engineering_domain_features
             SET status='SCHEDULING', architecture_version=:architecture_version, last_error=NULL, updated_at=UTC_TIMESTAMP(6)
             WHERE id=:id AND status IN ('NOT_STARTED','READY','STALE','REVALIDATION_REQUIRED')",
            ['id' => $featureId, 'architecture_version' => $architectureVersion],
        );
    }

    public function bindEngineeringFeature(string $domainFeatureId, string $engineeringFeatureId): void
    {
        $this->connection->update('cos_engineering_domain_features', [
            'engineering_feature_id' => $engineeringFeatureId,
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
        ], ['id' => $domainFeatureId]);
    }

    public function updateFeatureStatus(string $featureId, DomainFeatureStatus $status, ?string $error = null): void
    {
        $this->connection->update('cos_engineering_domain_features', [
            'status' => $status->value,
            'last_error' => $error,
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
        ], ['id' => $featureId]);
    }

    public function registerContract(
        string $id,
        string $domainId,
        string $contractKey,
        string $type,
        string $version,
        string $compatibility,
        ?string $producerFeatureId,
        array $consumers,
        array $schema,
        string $status = 'ACTIVE',
    ): void {
        $existing = $this->contractByKey($domainId, $contractKey);
        if ($existing === null) {
            $this->connection->insert('cos_engineering_domain_contracts', [
                'id' => $id,
                'domain_id' => $domainId,
                'contract_key' => $contractKey,
                'contract_type' => $type,
                'version' => $version,
                'compatibility' => $compatibility,
                'producer_feature_id' => $producerFeatureId,
                'consumers' => $this->json($consumers),
                'schema_json' => $this->json($schema),
                'status' => $status,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
                'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
            ]);
        } else {
            $this->connection->update('cos_engineering_domain_contracts', [
                'contract_type' => $type,
                'version' => $version,
                'compatibility' => $compatibility,
                'producer_feature_id' => $producerFeatureId,
                'consumers' => $this->json($consumers),
                'schema_json' => $this->json($schema),
                'status' => $status,
                'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
            ], ['id' => $existing['id']]);
        }
        $this->audit($domainId, 'CONTRACT_REGISTERED', [
            'contract_key' => $contractKey,
            'type' => $type,
            'version' => $version,
            'compatibility' => $compatibility,
        ]);
    }

    /** @return array<string,mixed>|null */
    public function contractByKey(string $domainId, string $contractKey): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM cos_engineering_domain_contracts WHERE domain_id=:domain_id AND contract_key=:contract_key',
            ['domain_id' => $domainId, 'contract_key' => $contractKey],
        );
        return $row === false ? null : $this->decodeContract($row);
    }

    /** @return list<array<string,mixed>> */
    public function contracts(string $domainId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM cos_engineering_domain_contracts WHERE domain_id=:domain_id ORDER BY contract_key',
            ['domain_id' => $domainId],
        );
        return array_map(fn (array $row): array => $this->decodeContract($row), $rows);
    }

    public function markConsumerFeaturesStale(string $domainId, array $consumerFeatureIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $consumerFeatureIds))));
        if ($ids === []) return;
        $params = ['domain_id' => $domainId];
        $placeholders = [];
        foreach ($ids as $index => $id) {
            $key = 'id_'.$index;
            $placeholders[] = ':'.$key;
            $params[$key] = $id;
        }
        $this->connection->executeStatement(
            "UPDATE cos_engineering_domain_features
             SET status=CASE WHEN status='COMPLETED' THEN 'STALE' ELSE 'REVALIDATION_REQUIRED' END,
                 updated_at=UTC_TIMESTAMP(6)
             WHERE domain_id=:domain_id AND id IN (".implode(',', $placeholders).") AND status NOT IN ('RUNNING','SCHEDULING','CANCELLED')",
            $params,
        );
    }

    public function createArtifact(
        string $domainId,
        string $type,
        array $content,
        int $architectureVersion,
        ?string $createdBy = null,
    ): array {
        $previous = $this->connection->fetchAssociative(
            'SELECT * FROM cos_engineering_domain_artifacts WHERE domain_id=:domain_id AND type=:type AND status="ACTIVE" ORDER BY version DESC LIMIT 1',
            ['domain_id' => $domainId, 'type' => $type],
        );
        $version = $previous === false ? 1 : ((int) $previous['version'] + 1);
        if ($previous !== false) {
            $this->connection->update('cos_engineering_domain_artifacts', ['status' => 'SUPERSEDED'], ['id' => $previous['id']]);
        }

        $normalized = $this->normalize($content);
        $hash = hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $id = EngineeringId::generate();
        $this->connection->insert('cos_engineering_domain_artifacts', [
            'id' => $id,
            'domain_id' => $domainId,
            'type' => $type,
            'version' => $version,
            'status' => 'ACTIVE',
            'content' => $this->json($content),
            'content_hash' => $hash,
            'architecture_version' => $architectureVersion,
            'created_by' => $createdBy,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
        ]);

        return [
            'id' => $id,
            'domain_id' => $domainId,
            'type' => $type,
            'version' => $version,
            'status' => 'ACTIVE',
            'content' => $content,
            'content_hash' => $hash,
            'architecture_version' => $architectureVersion,
            'created_by' => $createdBy,
        ];
    }

    public function recordDomainQa(string $domainId, string $status, array $report, ?string $actor = null): void
    {
        $domain = $this->domain($domainId);
        $this->connection->update('cos_engineering_domains', [
            'qa_status' => $status,
            'status' => DomainDevelopmentStatus::DOMAIN_QA->value,
            'release_status' => 'NOT_READY',
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
        ], ['id' => $domainId]);
        $this->createArtifact($domainId, 'DOMAIN_QA_REPORT', $report, (int) $domain['architecture_version'], $actor ?? 'QA');
        $this->audit($domainId, 'DOMAIN_QA_RECORDED', ['status' => $status], $actor);
    }

    public function setReleaseReady(string $domainId, array $manifest, ?string $actor = null): void
    {
        $domain = $this->domain($domainId);
        $this->connection->update('cos_engineering_domains', [
            'status' => DomainDevelopmentStatus::RELEASE_READY->value,
            'release_status' => 'READY',
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
        ], ['id' => $domainId]);
        $this->createArtifact($domainId, 'DOMAIN_RELEASE_MANIFEST', $manifest, (int) $domain['architecture_version'], $actor);
        $this->audit($domainId, 'DOMAIN_RELEASE_READY', ['manifest' => $manifest], $actor);
    }

    /** @return list<array<string,mixed>> */
    public function auditTrail(string $domainId, int $limit = 250): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM cos_engineering_domain_audit WHERE domain_id=:domain_id ORDER BY created_at DESC LIMIT '.max(1, min(1000, $limit)),
            ['domain_id' => $domainId],
        );
        return array_map(function (array $row): array {
            $row['payload'] = $this->decodeJson($row['payload'] ?? null);
            return $row;
        }, $rows);
    }

    public function audit(string $domainId, string $event, array $payload = [], ?string $actor = null): void
    {
        $this->connection->insert('cos_engineering_domain_audit', [
            'id' => EngineeringId::generate(),
            'domain_id' => $domainId,
            'event' => $event,
            'actor' => $actor,
            'payload' => $this->json($payload),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
        ]);
    }

    private function decodeDomain(array $row): array
    {
        foreach (['master_specification','scope_json','out_of_scope_json','architecture_json','constitution_json','domain_acceptance_criteria'] as $key) {
            $row[$key] = $this->decodeJson($row[$key] ?? null);
        }
        $row['architecture_version'] = (int) ($row['architecture_version'] ?? 0);
        return $row;
    }

    private function decodeFeature(array $row): array
    {
        foreach (['owned_paths','shared_paths','forbidden_paths'] as $key) $row[$key] = $this->decodeJson($row[$key] ?? null);
        $row['required'] = (bool) ($row['required'] ?? false);
        $row['architecture_version'] = (int) ($row['architecture_version'] ?? 0);
        return $row;
    }

    private function decodeContract(array $row): array
    {
        $row['consumers'] = $this->decodeJson($row['consumers'] ?? null);
        $row['schema'] = $this->decodeJson($row['schema_json'] ?? null);
        unset($row['schema_json']);
        return $row;
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (!is_string($value) || trim($value) === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function normalize(array $value): array
    {
        foreach ($value as &$item) if (is_array($item)) $item = $this->normalize($item);
        unset($item);
        if (!array_is_list($value)) ksort($value);
        return $value;
    }
}
