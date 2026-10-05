<?php
declare(strict_types=1);

namespace App\Engineering\Infrastructure\Persistence\Doctrine;

use App\Engineering\Application\Persistence\EngineeringDomainStoreInterface;
use App\Engineering\Domain\DomainDevelopment\EngineeringDomainFeatureStatus;
use App\Engineering\Domain\DomainDevelopment\EngineeringDomainStatus;
use App\Engineering\Domain\Workflow\EngineeringId;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

final readonly class DoctrineEngineeringDomainStore implements EngineeringDomainStoreInterface
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function create(
        string $id,
        string $organizationId,
        string $domainKey,
        string $name,
        string $masterSpecification,
        string $targetRepository,
        string $targetBranch,
        string $createdBy,
        int $maxParallelFeatures = 3,
        int $maxParallelDevelopers = 2,
        int $maxParallelReviews = 2,
        int $maxParallelQa = 2,
    ): void {
        $id = EngineeringId::assert($id);
        $domainKey = $this->key($domainKey);
        if ($name === '' || trim($masterSpecification) === '') throw new \InvalidArgumentException('Domain name and master specification are required.');

        $this->db()->insert('cos_engineering_domains', [
            'id' => $id,
            'organization_id' => $organizationId,
            'domain_key' => $domainKey,
            'name' => trim($name),
            'status' => EngineeringDomainStatus::DRAFT->value,
            'version' => 1,
            'master_specification' => $masterSpecification,
            'target_repository' => trim($targetRepository),
            'target_branch' => trim($targetBranch) !== '' ? trim($targetBranch) : 'main',
            'max_parallel_features' => max(1, min(20, $maxParallelFeatures)),
            'max_parallel_developers' => max(1, min(20, $maxParallelDevelopers)),
            'max_parallel_reviews' => max(1, min(20, $maxParallelReviews)),
            'max_parallel_qa' => max(1, min(20, $maxParallelQa)),
            'status_reason' => null,
            'created_by' => $createdBy,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);
    }

    public function domain(string $id): array
    {
        $row = $this->db()->fetchAssociative('SELECT * FROM cos_engineering_domains WHERE id = :id', ['id' => EngineeringId::assert($id)]);
        if (!is_array($row)) throw new RuntimeException('Engineering domain initiative not found: '.$id);
        return $this->domainView($row);
    }

    public function domainsForOrganization(string $organizationId, int $limit = 50): array
    {
        $rows = $this->db()->fetchAllAssociative(
            'SELECT * FROM cos_engineering_domains WHERE organization_id = :organization_id ORDER BY updated_at DESC LIMIT '.max(1, min(100, $limit)),
            ['organization_id' => $organizationId],
        );
        return array_map(fn (array $row): array => $this->domainView($row), $rows);
    }

    public function updateStatus(string $id, string $status, ?string $reason = null): void
    {
        EngineeringDomainStatus::from($status);
        $updated = $this->db()->update('cos_engineering_domains', [
            'status' => $status,
            'status_reason' => $reason,
            'updated_at' => $this->now(),
        ], ['id' => EngineeringId::assert($id)]);
        if ($updated === 0) throw new RuntimeException('Engineering domain initiative not found: '.$id);
    }

    public function saveArtifact(string $domainId, string $type, array $content, string $createdBy): array
    {
        $domainId = EngineeringId::assert($domainId);
        $type = strtoupper(trim($type));
        if ($type === '') throw new \InvalidArgumentException('Domain artifact type is required.');

        $db = $this->db();
        $previous = $db->fetchAssociative(
            "SELECT * FROM cos_engineering_domain_artifacts WHERE domain_id=:domain_id AND type=:type AND status='ACTIVE' ORDER BY version DESC LIMIT 1",
            ['domain_id' => $domainId, 'type' => $type],
        );

        $normalized = $this->normalize($content);
        $hash = hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if (is_array($previous) && hash_equals((string) ($previous['content_hash'] ?? ''), $hash)) {
            return $this->artifactView($previous);
        }

        $version = is_array($previous) ? ((int) $previous['version'] + 1) : 1;
        if (is_array($previous)) {
            $db->update('cos_engineering_domain_artifacts', ['status' => 'SUPERSEDED'], ['id' => $previous['id']]);
        }

        $id = EngineeringId::generate();
        $db->insert('cos_engineering_domain_artifacts', [
            'id' => $id,
            'domain_id' => $domainId,
            'type' => $type,
            'version' => $version,
            'status' => 'ACTIVE',
            'content' => json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'content_hash' => $hash,
            'supersedes_artifact_id' => is_array($previous) ? $previous['id'] : null,
            'created_by' => $createdBy,
            'created_at' => $this->now(),
        ]);

        return [
            'id' => $id,
            'domain_id' => $domainId,
            'type' => $type,
            'version' => $version,
            'status' => 'ACTIVE',
            'content' => $content,
            'content_hash' => $hash,
            'supersedes_artifact_id' => is_array($previous) ? $previous['id'] : null,
            'created_by' => $createdBy,
        ];
    }

    public function latestArtifact(string $domainId, string $type): ?array
    {
        $row = $this->db()->fetchAssociative(
            "SELECT * FROM cos_engineering_domain_artifacts WHERE domain_id=:domain_id AND type=:type AND status='ACTIVE' ORDER BY version DESC LIMIT 1",
            ['domain_id' => EngineeringId::assert($domainId), 'type' => strtoupper(trim($type))],
        );
        return is_array($row) ? $this->artifactView($row) : null;
    }

    public function artifacts(string $domainId): array
    {
        $rows = $this->db()->fetchAllAssociative(
            "SELECT * FROM cos_engineering_domain_artifacts WHERE domain_id=:domain_id AND status='ACTIVE' ORDER BY type ASC, version DESC",
            ['domain_id' => EngineeringId::assert($domainId)],
        );
        return array_map(fn (array $row): array => $this->artifactView($row), $rows);
    }

    public function replacePlan(string $domainId, array $capabilities, array $features, array $dependencies): void
    {
        $domainId = EngineeringId::assert($domainId);
        $db = $this->db();
        $linked = (int) $db->fetchOne(
            'SELECT COUNT(*) FROM cos_engineering_domain_features WHERE domain_id=:domain_id AND engineering_feature_id IS NOT NULL',
            ['domain_id' => $domainId],
        );
        if ($linked > 0) throw new RuntimeException('Domain plan cannot be replaced after feature workflows have been linked.');

        $db->beginTransaction();
        try {
            $db->delete('cos_engineering_domain_dependencies', ['domain_id' => $domainId]);
            $db->delete('cos_engineering_domain_features', ['domain_id' => $domainId]);
            $db->delete('cos_engineering_domain_capabilities', ['domain_id' => $domainId]);

            foreach ($capabilities as $capability) {
                $key = $this->key((string) ($capability['key'] ?? ''));
                $db->insert('cos_engineering_domain_capabilities', [
                    'id' => EngineeringId::generate(),
                    'domain_id' => $domainId,
                    'capability_key' => $key,
                    'name' => trim((string) ($capability['name'] ?? $key)),
                    'description' => (string) ($capability['description'] ?? ''),
                    'kind' => strtoupper((string) ($capability['kind'] ?? 'CORE')),
                    'required' => ($capability['required'] ?? true) ? 1 : 0,
                    'status' => 'NOT_STARTED',
                    'sort_order' => (int) ($capability['sort_order'] ?? 0),
                    'metadata' => json_encode($capability, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_at' => $this->now(),
                    'updated_at' => $this->now(),
                ]);
            }

            foreach ($features as $feature) {
                $key = $this->key((string) ($feature['key'] ?? ''));
                $ownedPaths = $this->stringList($feature['owned_paths'] ?? []);
                $sharedPaths = $this->stringList($feature['shared_paths'] ?? []);
                $forbiddenPaths = $this->stringList($feature['forbidden_paths'] ?? []);
                $db->insert('cos_engineering_domain_features', [
                    'id' => EngineeringId::generate(),
                    'domain_id' => $domainId,
                    'feature_key' => $key,
                    'capability_key' => $this->key((string) ($feature['capability_key'] ?? 'core')),
                    'title' => trim((string) ($feature['title'] ?? $key)),
                    'description' => (string) ($feature['description'] ?? ''),
                    'kind' => strtoupper((string) ($feature['kind'] ?? 'CORE')),
                    'priority' => strtoupper((string) ($feature['priority'] ?? 'P2')),
                    'risk' => strtoupper((string) ($feature['risk'] ?? 'MEDIUM')),
                    'required' => ($feature['required'] ?? true) ? 1 : 0,
                    'status' => EngineeringDomainFeatureStatus::NOT_STARTED->value,
                    'acceptance_criteria' => json_encode(is_array($feature['acceptance_criteria'] ?? null) ? $feature['acceptance_criteria'] : [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'owned_paths' => json_encode($ownedPaths, JSON_THROW_ON_ERROR),
                    'shared_paths' => json_encode($sharedPaths, JSON_THROW_ON_ERROR),
                    'forbidden_paths' => json_encode($forbiddenPaths, JSON_THROW_ON_ERROR),
                    'contracts_consumed' => json_encode($this->stringList($feature['contracts_consumed'] ?? []), JSON_THROW_ON_ERROR),
                    'contracts_produced' => json_encode($this->stringList($feature['contracts_produced'] ?? []), JSON_THROW_ON_ERROR),
                    'engineering_feature_id' => null,
                    'architecture_version' => null,
                    'contract_snapshot' => null,
                    'status_reason' => null,
                    'metadata' => json_encode($feature, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_at' => $this->now(),
                    'updated_at' => $this->now(),
                ]);
            }

            foreach ($dependencies as $dependency) {
                $db->insert('cos_engineering_domain_dependencies', [
                    'id' => EngineeringId::generate(),
                    'domain_id' => $domainId,
                    'feature_key' => $this->key((string) ($dependency['feature_key'] ?? '')),
                    'depends_on_key' => $this->key((string) ($dependency['depends_on_key'] ?? '')),
                    'dependency_type' => strtoupper((string) ($dependency['type'] ?? 'REQUIRES')),
                    'created_at' => $this->now(),
                ]);
            }

            $db->commit();
        } catch (\Throwable $error) {
            if ($db->isTransactionActive()) $db->rollBack();
            throw $error;
        }
    }

    public function capabilities(string $domainId): array
    {
        $rows = $this->db()->fetchAllAssociative(
            'SELECT * FROM cos_engineering_domain_capabilities WHERE domain_id=:domain_id ORDER BY sort_order ASC, capability_key ASC',
            ['domain_id' => EngineeringId::assert($domainId)],
        );
        return array_map(function (array $row): array {
            $row['required'] = (bool) ($row['required'] ?? false);
            $row['metadata'] = $this->json($row['metadata'] ?? null);
            return $row;
        }, $rows);
    }

    public function updateCapabilityStatus(string $domainId, string $capabilityKey, string $status, ?string $reason = null): void
    {
        $status = strtoupper(trim($status));
        if (!in_array($status, ['NOT_STARTED','WAITING','RUNNING','IMPLEMENTED','REVALIDATION_REQUIRED','BLOCKED','COMPLETE'], true)) {
            throw new \InvalidArgumentException('Invalid Domain capability status: '.$status);
        }

        $metadata = $this->db()->fetchOne(
            'SELECT metadata FROM cos_engineering_domain_capabilities WHERE domain_id=:domain_id AND capability_key=:capability_key',
            ['domain_id' => EngineeringId::assert($domainId), 'capability_key' => $this->key($capabilityKey)],
        );
        if ($metadata === false) throw new RuntimeException('Domain capability not found: '.$capabilityKey);
        $payload = $this->json($metadata);
        if ($reason !== null && trim($reason) !== '') $payload['status_reason'] = trim($reason);
        else unset($payload['status_reason']);

        $updated = $this->db()->update('cos_engineering_domain_capabilities', [
            'status' => $status,
            'metadata' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => $this->now(),
        ], [
            'domain_id' => EngineeringId::assert($domainId),
            'capability_key' => $this->key($capabilityKey),
        ]);
        if ($updated === 0) throw new RuntimeException('Domain capability not found: '.$capabilityKey);
    }

    public function features(string $domainId): array
    {
        $rows = $this->db()->fetchAllAssociative(
            'SELECT * FROM cos_engineering_domain_features WHERE domain_id=:domain_id ORDER BY FIELD(kind,\'FOUNDATION\',\'CORE\',\'INTEGRATION\',\'APPLICATION\',\'UI\',\'INFRASTRUCTURE\'), priority ASC, feature_key ASC',
            ['domain_id' => EngineeringId::assert($domainId)],
        );
        return array_map(fn (array $row): array => $this->featureView($row), $rows);
    }

    public function feature(string $domainId, string $featureKey): array
    {
        $row = $this->db()->fetchAssociative(
            'SELECT * FROM cos_engineering_domain_features WHERE domain_id=:domain_id AND feature_key=:feature_key',
            ['domain_id' => EngineeringId::assert($domainId), 'feature_key' => $this->key($featureKey)],
        );
        if (!is_array($row)) throw new RuntimeException('Domain feature not found: '.$featureKey);
        return $this->featureView($row);
    }

    public function dependencies(string $domainId): array
    {
        return $this->db()->fetchAllAssociative(
            'SELECT feature_key, depends_on_key, dependency_type AS type FROM cos_engineering_domain_dependencies WHERE domain_id=:domain_id ORDER BY feature_key, depends_on_key',
            ['domain_id' => EngineeringId::assert($domainId)],
        );
    }

    public function linkEngineeringFeature(
        string $domainId,
        string $featureKey,
        string $engineeringFeatureId,
        int $architectureVersion,
        array $contractSnapshot,
    ): void {
        $domainId = EngineeringId::assert($domainId);
        $featureKey = $this->key($featureKey);
        $engineeringFeatureId = EngineeringId::assert($engineeringFeatureId);
        $db = $this->db();

        $current = $db->fetchAssociative(
            'SELECT engineering_feature_id, engineering_feature_history, architecture_version, contract_snapshot, status '
            .'FROM cos_engineering_domain_features WHERE domain_id=:domain_id AND feature_key=:feature_key',
            ['domain_id' => $domainId, 'feature_key' => $featureKey],
        );
        if (!is_array($current)) throw new RuntimeException('Domain feature not found: '.$featureKey);

        $history = $this->json($current['engineering_feature_history'] ?? null);
        $previousId = is_string($current['engineering_feature_id'] ?? null) ? trim((string) $current['engineering_feature_id']) : '';
        if ($previousId !== '' && $previousId !== $engineeringFeatureId) {
            $alreadyArchived = false;
            foreach ($history as $item) {
                if (is_array($item) && ($item['engineering_feature_id'] ?? null) === $previousId) {
                    $alreadyArchived = true;
                    break;
                }
            }
            if (!$alreadyArchived) {
                $history[] = [
                    'engineering_feature_id' => $previousId,
                    'architecture_version' => $current['architecture_version'] !== null ? (int) $current['architecture_version'] : null,
                    'contract_snapshot' => $this->json($current['contract_snapshot'] ?? null),
                    'status' => (string) ($current['status'] ?? ''),
                    'superseded_at' => $this->now(),
                ];
            }
        }

        $updated = $db->update('cos_engineering_domain_features', [
            'engineering_feature_id' => $engineeringFeatureId,
            'engineering_feature_history' => json_encode($history, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'architecture_version' => max(1, $architectureVersion),
            'contract_snapshot' => json_encode($contractSnapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => EngineeringDomainFeatureStatus::RUNNING->value,
            'status_reason' => null,
            'updated_at' => $this->now(),
        ], [
            'domain_id' => $domainId,
            'feature_key' => $featureKey,
        ]);
        if ($updated === 0) throw new RuntimeException('Domain feature not found: '.$featureKey);
    }

    public function updateFeatureStatus(string $domainId, string $featureKey, string $status, ?string $reason = null): void
    {
        EngineeringDomainFeatureStatus::from($status);
        $updated = $this->db()->update('cos_engineering_domain_features', [
            'status' => $status,
            'status_reason' => $reason,
            'updated_at' => $this->now(),
        ], [
            'domain_id' => EngineeringId::assert($domainId),
            'feature_key' => $this->key($featureKey),
        ]);
        if ($updated === 0) throw new RuntimeException('Domain feature not found: '.$featureKey);
    }

    public function replaceContracts(string $domainId, array $contracts): void
    {
        $domainId = EngineeringId::assert($domainId);
        $db = $this->db();
        $db->executeStatement(
            "UPDATE cos_engineering_domain_contracts SET status='SUPERSEDED', updated_at=:updated_at WHERE domain_id=:domain_id AND status='ACTIVE'",
            ['domain_id' => $domainId, 'updated_at' => $this->now()],
        );
        $ownerDomain = (string) $this->domain($domainId)['domain_key'];

        foreach ($contracts as $contract) {
            $name = trim((string) ($contract['name'] ?? ''));
            if ($name === '') throw new \InvalidArgumentException('Domain contract name is required.');
            $key = $this->key((string) ($contract['key'] ?? $name));
            $version = trim((string) ($contract['version'] ?? 'v1'));
            if ($version === '') throw new \InvalidArgumentException('Domain contract version is required.');

            $values = [
                'name' => $name,
                'type' => strtoupper((string) ($contract['type'] ?? 'DOMAIN_INTERFACE')),
                'owner_domain' => trim((string) ($contract['owner_domain'] ?? $ownerDomain)),
                'producer' => trim((string) ($contract['producer'] ?? '')),
                'consumers' => json_encode($this->stringList($contract['consumers'] ?? []), JSON_THROW_ON_ERROR),
                'schema_payload' => json_encode(is_array($contract['schema'] ?? null) ? $contract['schema'] : [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'compatibility' => strtoupper((string) ($contract['compatibility'] ?? 'BACKWARD_COMPATIBLE')),
                'status' => 'ACTIVE',
                'updated_at' => $this->now(),
            ];
            $existing = $db->fetchOne(
                'SELECT id FROM cos_engineering_domain_contracts WHERE domain_id=:domain_id AND contract_key=:contract_key AND version=:version',
                ['domain_id' => $domainId, 'contract_key' => $key, 'version' => $version],
            );
            if (is_string($existing) && $existing !== '') {
                $db->update('cos_engineering_domain_contracts', $values, ['id' => $existing]);
                continue;
            }
            $db->insert('cos_engineering_domain_contracts', array_merge([
                'id' => EngineeringId::generate(),
                'domain_id' => $domainId,
                'contract_key' => $key,
                'version' => $version,
            ], $values));
        }
    }

    public function contracts(string $domainId): array
    {
        $rows = $this->db()->fetchAllAssociative(
            "SELECT * FROM cos_engineering_domain_contracts WHERE domain_id=:domain_id AND status='ACTIVE' ORDER BY contract_key",
            ['domain_id' => EngineeringId::assert($domainId)],
        );
        return array_map(function (array $row): array {
            $row['consumers'] = $this->json($row['consumers'] ?? null);
            $row['schema'] = $this->json($row['schema_payload'] ?? null);
            unset($row['schema_payload']);
            return $row;
        }, $rows);
    }

    public function replaceEvents(string $domainId, array $events): void
    {
        $domainId = EngineeringId::assert($domainId);
        $db = $this->db();
        $db->executeStatement(
            "UPDATE cos_engineering_domain_events SET status='SUPERSEDED', updated_at=:updated_at WHERE domain_id=:domain_id AND status='ACTIVE'",
            ['domain_id' => $domainId, 'updated_at' => $this->now()],
        );

        foreach ($events as $event) {
            $name = trim((string) ($event['name'] ?? ''));
            if ($name === '') throw new \InvalidArgumentException('Domain event name is required.');
            $key = $this->key((string) ($event['key'] ?? $name));
            $version = trim((string) ($event['version'] ?? 'v1'));
            if ($version === '') throw new \InvalidArgumentException('Domain event version is required.');
            $values = [
                'name' => $name,
                'producer' => trim((string) ($event['producer'] ?? '')),
                'consumers' => json_encode($this->stringList($event['consumers'] ?? []), JSON_THROW_ON_ERROR),
                'payload_schema' => json_encode(is_array($event['payload_schema'] ?? null) ? $event['payload_schema'] : [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'delivery' => strtoupper((string) ($event['delivery'] ?? 'AT_LEAST_ONCE')),
                'idempotency' => (string) ($event['idempotency'] ?? ''),
                'ordering_rule' => (string) ($event['ordering'] ?? ''),
                'status' => 'ACTIVE',
                'updated_at' => $this->now(),
            ];
            $existing = $db->fetchOne(
                'SELECT id FROM cos_engineering_domain_events WHERE domain_id=:domain_id AND event_key=:event_key AND version=:version',
                ['domain_id' => $domainId, 'event_key' => $key, 'version' => $version],
            );
            if (is_string($existing) && $existing !== '') {
                $db->update('cos_engineering_domain_events', $values, ['id' => $existing]);
                continue;
            }
            $db->insert('cos_engineering_domain_events', array_merge([
                'id' => EngineeringId::generate(),
                'domain_id' => $domainId,
                'event_key' => $key,
                'version' => $version,
            ], $values));
        }
    }

    public function events(string $domainId): array
    {
        $rows = $this->db()->fetchAllAssociative(
            "SELECT * FROM cos_engineering_domain_events WHERE domain_id=:domain_id AND status='ACTIVE' ORDER BY event_key",
            ['domain_id' => EngineeringId::assert($domainId)],
        );
        return array_map(function (array $row): array {
            $row['consumers'] = $this->json($row['consumers'] ?? null);
            $row['payload_schema'] = $this->json($row['payload_schema'] ?? null);
            return $row;
        }, $rows);
    }

    public function reservePaths(string $domainId, string $featureKey, array $paths): bool
    {
        $domainId = EngineeringId::assert($domainId);
        $featureKey = $this->key($featureKey);
        $paths = array_values(array_unique(array_filter(array_map([$this, 'path'], $paths))));
        if ($paths === []) return true;

        $db = $this->db();
        $placeholders = implode(',', array_fill(0, count($paths), '?'));
        $params = array_merge([$domainId, $featureKey], $paths);
        $conflicts = $db->fetchAllAssociative(
            'SELECT path, feature_key FROM cos_engineering_domain_path_reservations WHERE domain_id=? AND feature_key<>? AND path IN ('.$placeholders.')',
            $params,
        );
        if ($conflicts !== []) return false;

        foreach ($paths as $path) {
            try {
                $db->insert('cos_engineering_domain_path_reservations', [
                    'id' => EngineeringId::generate(),
                    'domain_id' => $domainId,
                    'feature_key' => $featureKey,
                    'path' => $path,
                    'reserved_at' => $this->now(),
                ]);
            } catch (\Throwable) {
                return false;
            }
        }
        return true;
    }

    public function releasePaths(string $domainId, string $featureKey): void
    {
        $this->db()->delete('cos_engineering_domain_path_reservations', [
            'domain_id' => EngineeringId::assert($domainId),
            'feature_key' => $this->key($featureKey),
        ]);
    }

    public function pathReservations(string $domainId): array
    {
        return $this->db()->fetchAllAssociative(
            'SELECT feature_key, path, reserved_at FROM cos_engineering_domain_path_reservations WHERE domain_id=:domain_id ORDER BY path',
            ['domain_id' => EngineeringId::assert($domainId)],
        );
    }

    public function recordAgentRun(
        string $domainId,
        string $role,
        string $status,
        string $correlationId,
        ?string $provider,
        ?string $model,
        array $usage,
        ?string $error,
    ): void {
        $this->db()->insert('cos_engineering_domain_agent_runs', [
            'id' => EngineeringId::generate(),
            'domain_id' => EngineeringId::assert($domainId),
            'agent_role' => $role,
            'status' => $status,
            'correlation_id' => mb_substr($correlationId, 0, 128),
            'provider' => $provider,
            'model' => $model,
            'usage_payload' => json_encode($usage, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'error_message' => $error,
            'created_at' => $this->now(),
        ]);
    }

    public function agentRuns(string $domainId): array
    {
        $rows = $this->db()->fetchAllAssociative(
            'SELECT * FROM cos_engineering_domain_agent_runs WHERE domain_id=:domain_id ORDER BY created_at DESC, id DESC',
            ['domain_id' => EngineeringId::assert($domainId)],
        );
        return array_map(function (array $row): array {
            $row['usage'] = $this->json($row['usage_payload'] ?? null);
            unset($row['usage_payload']);
            return $row;
        }, $rows);
    }

    public function recordRuntimeEvent(
        string $domainId,
        string $organizationId,
        string $eventType,
        ?string $featureKey,
        array $payload,
        string $correlationId,
        string $dedupeKey,
        string $actor = 'SYSTEM',
        ?string $reason = null,
        ?string $artifactId = null,
        ?string $repositoryRevision = null,
        ?string $result = null,
    ): void {
        $eventType = trim($eventType);
        $dedupeKey = trim($dedupeKey);
        if ($eventType === '' || $dedupeKey === '') throw new \InvalidArgumentException('Domain runtime event type and dedupe key are required.');

        $this->db()->executeStatement(
            'INSERT IGNORE INTO cos_engineering_domain_runtime_events '
            .'(id, domain_id, organization_id, event_type, feature_key, actor, reason, artifact_id, repository_revision, result, payload, correlation_id, dedupe_key, created_at) '
            .'VALUES (:id, :domain_id, :organization_id, :event_type, :feature_key, :actor, :reason, :artifact_id, :repository_revision, :result, :payload, :correlation_id, :dedupe_key, :created_at)',
            [
                'id' => EngineeringId::generate(),
                'domain_id' => EngineeringId::assert($domainId),
                'organization_id' => $organizationId,
                'event_type' => $eventType,
                'feature_key' => $featureKey !== null && trim($featureKey) !== '' ? $this->key($featureKey) : null,
                'actor' => mb_substr(trim($actor) !== '' ? trim($actor) : 'SYSTEM', 0, 128),
                'reason' => $reason !== null ? mb_substr(trim($reason), 0, 500) : null,
                'artifact_id' => $artifactId !== null && trim($artifactId) !== '' ? EngineeringId::assert($artifactId) : null,
                'repository_revision' => $repositoryRevision !== null && trim($repositoryRevision) !== '' ? mb_substr(trim($repositoryRevision), 0, 128) : null,
                'result' => $result !== null && trim($result) !== '' ? mb_substr(strtoupper(trim($result)), 0, 64) : null,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'correlation_id' => mb_substr(trim($correlationId), 0, 128),
                'dedupe_key' => mb_substr($dedupeKey, 0, 191),
                'created_at' => $this->now(),
            ],
        );
    }

    public function runtimeEvents(string $domainId, int $limit = 200): array
    {
        $rows = $this->db()->fetchAllAssociative(
            'SELECT * FROM cos_engineering_domain_runtime_events WHERE domain_id=:domain_id ORDER BY created_at DESC, id DESC LIMIT '.max(1, min(500, $limit)),
            ['domain_id' => EngineeringId::assert($domainId)],
        );
        return array_map(function (array $row): array {
            $row['payload'] = $this->json($row['payload'] ?? null);
            return $row;
        }, $rows);
    }

    private function domainView(array $row): array
    {
        $row['version'] = (int) $row['version'];
        $row['max_parallel_features'] = (int) $row['max_parallel_features'];
        $row['max_parallel_developers'] = (int) ($row['max_parallel_developers'] ?? 2);
        $row['max_parallel_reviews'] = (int) ($row['max_parallel_reviews'] ?? 2);
        $row['max_parallel_qa'] = (int) ($row['max_parallel_qa'] ?? 2);
        return $row;
    }

    private function featureView(array $row): array
    {
        foreach (['acceptance_criteria','owned_paths','shared_paths','forbidden_paths','contracts_consumed','contracts_produced','contract_snapshot','engineering_feature_history','metadata'] as $key) {
            $row[$key] = $this->json($row[$key] ?? null);
        }
        $row['required'] = (bool) $row['required'];
        $row['architecture_version'] = $row['architecture_version'] !== null ? (int) $row['architecture_version'] : null;
        return $row;
    }

    private function artifactView(array $row): array
    {
        $row['version'] = (int) $row['version'];
        $row['content'] = $this->json($row['content'] ?? null);
        return $row;
    }

    private function json(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (!is_string($value) || trim($value) === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function key(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9._-]+/', '-', $value) ?: '';
        $value = trim($value, '.-_');
        if ($value === '') throw new \InvalidArgumentException('Domain key cannot be empty.');
        return mb_substr($value, 0, 96);
    }

    private function path(mixed $value): string
    {
        if (!is_scalar($value)) return '';
        $path = str_replace('\\', '/', trim((string) $value));
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, "\0")) return '';
        return rtrim($path, '/');
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) return [];
        $out = [];
        foreach ($value as $item) if (is_scalar($item) && trim((string) $item) !== '') $out[] = trim((string) $item);
        return array_values(array_unique($out));
    }

    private function normalize(array $value): array
    {
        foreach ($value as &$item) if (is_array($item)) $item = $this->normalize($item);
        unset($item);
        if (!array_is_list($value)) ksort($value);
        return $value;
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        return $this->entityManager->getConnection();
    }

    private function now(): string
    {
        return (new DateTimeImmutable())->format('Y-m-d H:i:s.u');
    }
}
