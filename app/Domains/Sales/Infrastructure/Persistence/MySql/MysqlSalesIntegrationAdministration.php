<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\Persistence\MySql;

use DomainException;
use Domains\Sales\Application\Contract\SalesIntegrationAdministrationInterface;
use Domains\Sales\Application\Contract\SalesIntegrationHealthProbeInterface;
use Domains\Sales\Automation\Integration\SalesIntegrationDefinitionCatalog;
use PDO;
use Throwable;

final readonly class MysqlSalesIntegrationAdministration implements SalesIntegrationAdministrationInterface
{
    public function __construct(
        private PDO $connection,
        private SalesIntegrationHealthProbeInterface $healthProbe,
        private SalesIntegrationDefinitionCatalog $definitions = new SalesIntegrationDefinitionCatalog(),
    ) {
    }

    public function catalog(): array
    {
        return [
            'definitions' => $this->definitions->all(),
            'lifecycle_statuses' => ['DRAFT', 'ACTIVE', 'DISABLED', 'ARCHIVED'],
            'health_statuses' => ['UNKNOWN', 'HEALTHY', 'DEGRADED', 'ERROR'],
            'assignment_strategies' => ['KEEP_UNASSIGNED', 'TEAM_DEFAULT', 'ROUND_ROBIN'],
            'credential_reference_schemes' => ['env:'],
        ];
    }

    public function integrations(string $organizationId): array
    {
        $statement = $this->connection->prepare(
            'SELECT id,integration_key,capability,provider,name,status,configuration_version,health_status,is_primary,
                    config,credentials_reference,last_health_check_at,last_success_at,last_error,created_at,updated_at
             FROM cos_integrations
             WHERE organization_id=:organization_id
             ORDER BY status="ACTIVE" DESC,name,id'
        );
        $statement->execute(['organization_id' => $organizationId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row = $this->publicIntegration($row);
            $row['route_count'] = $this->routeCount($organizationId, (int) $row['id']);
        }
        unset($row);
        return $rows;
    }

    public function routingOptions(string $organizationId): array
    {
        $pipelines = $this->connection->prepare(
            'SELECT id,code,name,status,initial_stage_id
             FROM sales_pipelines
             WHERE organization_id=:organization_id AND status<>"ARCHIVED"
             ORDER BY status="ACTIVE" DESC,name,id'
        );
        $pipelines->execute(['organization_id' => $organizationId]);

        $stages = $this->connection->prepare(
            'SELECT s.id,s.pipeline_id,s.code,s.name,s.sort_order
             FROM sales_pipeline_stages s
             INNER JOIN sales_pipelines p ON p.id=s.pipeline_id AND p.organization_id=s.organization_id
             WHERE s.organization_id=:organization_id AND s.status="ACTIVE" AND p.status<>"ARCHIVED"
             ORDER BY p.name,s.sort_order,s.id'
        );
        $stages->execute(['organization_id' => $organizationId]);

        $teams = $this->connection->prepare(
            'SELECT id,code,name,status
             FROM sales_teams
             WHERE organization_id=:organization_id AND status="ACTIVE"
             ORDER BY name,id'
        );
        $teams->execute(['organization_id' => $organizationId]);

        return [
            'pipelines' => $pipelines->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'stages' => $stages->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'teams' => $teams->fetchAll(PDO::FETCH_ASSOC) ?: [],
        ];
    }

    public function integration(string $organizationId, int $integrationId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id,integration_key,capability,provider,name,status,configuration_version,health_status,is_primary,
                    config,credentials_reference,last_health_check_at,last_success_at,last_error,created_by,updated_by,created_at,updated_at
             FROM cos_integrations
             WHERE organization_id=:organization_id AND id=:id LIMIT 1'
        );
        $statement->execute(['organization_id' => $organizationId, 'id' => $integrationId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $result = $this->publicIntegration($row);
        $result['routes'] = $this->routes($organizationId, $integrationId);
        return $result;
    }

    public function create(string $organizationId, array $input, string $actorId): array
    {
        $definition = $this->definitions->get(trim((string) ($input['integration_key'] ?? '')));
        $name = mb_substr(trim((string) ($input['name'] ?? $definition['name'])), 0, 160);
        if ($name === '') {
            throw new DomainException('Integration name is required.');
        }
        $config = $this->definitions->normalizeConfig($definition, (array) ($input['config'] ?? []));
        $credentialsReference = $this->credentialsReference($input['credentials_reference'] ?? null);
        if (($definition['credentials_required'] ?? false) && $credentialsReference === null) {
            throw new DomainException('Credentials reference is required.');
        }

        $this->transactional(function () use ($organizationId, $definition, $name, $config, $credentialsReference, $actorId): void {
            $statement = $this->connection->prepare(
                'INSERT INTO cos_integrations
                 (organization_id,integration_key,capability,provider,name,status,configuration_version,health_status,is_primary,
                  config,credentials_reference,created_by,updated_by,created_at,updated_at)
                 VALUES (:organization_id,:integration_key,:capability,:provider,:name,"DRAFT",1,"UNKNOWN",0,
                         :config,:credentials_reference,:actor_id,:actor_id,NOW(6),NOW(6))'
            );
            $statement->execute([
                'organization_id' => $organizationId,
                'integration_key' => $definition['integration_key'],
                'capability' => $definition['capability'],
                'provider' => $definition['provider'],
                'name' => $name,
                'config' => json_encode($config, JSON_THROW_ON_ERROR),
                'credentials_reference' => $credentialsReference,
                'actor_id' => $actorId,
            ]);
            $id = (int) $this->connection->lastInsertId();
            $this->revision($organizationId, 'INTEGRATION', 'integration:' . $id, 1, 'CREATE', $actorId, null, [
                'id' => $id,
                'integration_key' => $definition['integration_key'],
                'capability' => $definition['capability'],
                'provider' => $definition['provider'],
                'name' => $name,
                'status' => 'DRAFT',
                'config' => $config,
                'credentials_configured' => $credentialsReference !== null,
            ]);
        });

        $statement = $this->connection->prepare(
            'SELECT id FROM cos_integrations WHERE organization_id=:organization_id AND integration_key=:integration_key LIMIT 1'
        );
        $statement->execute(['organization_id' => $organizationId, 'integration_key' => $definition['integration_key']]);
        return $this->integration($organizationId, (int) $statement->fetchColumn()) ?? [];
    }

    public function update(string $organizationId, int $integrationId, array $input, int $expectedVersion, string $actorId): array
    {
        $current = $this->integration($organizationId, $integrationId);
        if ($current === null) {
            throw new DomainException('Sales integration was not found.');
        }
        if ($expectedVersion <= 0 || (int) $current['configuration_version'] !== $expectedVersion) {
            throw new DomainException('CONFIGURATION_CONFLICT');
        }

        $definition = $this->definitions->get((string) $current['integration_key']);
        $name = mb_substr(trim((string) ($input['name'] ?? $current['name'])), 0, 160);
        $status = strtoupper(trim((string) ($input['status'] ?? $current['status'])));
        if ($name === '' || !in_array($status, ['DRAFT', 'ACTIVE', 'DISABLED', 'ARCHIVED'], true)) {
            throw new DomainException('Invalid integration configuration.');
        }
        $config = array_key_exists('config', $input)
            ? $this->definitions->normalizeConfig($definition, (array) $input['config'])
            : (array) ($current['config'] ?? []);
        $credentialsReference = null;
        $replaceCredentials = array_key_exists('credentials_reference', $input);
        if ($replaceCredentials) {
            $credentialsReference = $this->credentialsReference($input['credentials_reference']);
        }

        $nextVersion = $expectedVersion + 1;
        $this->transactional(function () use (
            $organizationId, $integrationId, $name, $status, $config, $credentialsReference, $replaceCredentials,
            $expectedVersion, $nextVersion, $actorId, $current
        ): void {
            $sql = 'UPDATE cos_integrations SET name=:name,status=:status,config=:config,
                    configuration_version=:next_version,updated_by=:actor_id,updated_at=NOW(6)';
            if ($replaceCredentials) {
                $sql .= ',credentials_reference=:credentials_reference';
            }
            $sql .= ' WHERE organization_id=:organization_id AND id=:id AND configuration_version=:expected_version';
            $statement = $this->connection->prepare($sql);
            $params = [
                'name' => $name,
                'status' => $status,
                'config' => json_encode($config, JSON_THROW_ON_ERROR),
                'next_version' => $nextVersion,
                'actor_id' => $actorId,
                'organization_id' => $organizationId,
                'id' => $integrationId,
                'expected_version' => $expectedVersion,
            ];
            if ($replaceCredentials) {
                $params['credentials_reference'] = $credentialsReference;
            }
            $statement->execute($params);
            if ($statement->rowCount() !== 1) {
                throw new DomainException('CONFIGURATION_CONFLICT');
            }

            $after = [
                'id' => $integrationId,
                'integration_key' => $current['integration_key'],
                'capability' => $current['capability'],
                'provider' => $current['provider'],
                'name' => $name,
                'status' => $status,
                'configuration_version' => $nextVersion,
                'config' => $config,
                'credentials_configured' => $replaceCredentials ? $credentialsReference !== null : (bool) ($current['credentials_configured'] ?? false),
            ];
            $before = $this->revisionSnapshot($current);
            $action = match ($status) {
                'ARCHIVED' => 'ARCHIVE',
                'DISABLED' => 'DISABLE',
                'ACTIVE' => 'ACTIVATE',
                default => 'UPDATE',
            };
            $this->revision($organizationId, 'INTEGRATION', 'integration:' . $integrationId, $nextVersion, $action, $actorId, $before, $after);
        });

        return $this->integration($organizationId, $integrationId) ?? [];
    }

    public function testConnection(string $organizationId, int $integrationId): array
    {
        $statement = $this->connection->prepare(
            'SELECT capability,provider,config,credentials_reference,status
             FROM cos_integrations WHERE organization_id=:organization_id AND id=:id LIMIT 1'
        );
        $statement->execute(['organization_id' => $organizationId, 'id' => $integrationId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new DomainException('Sales integration was not found.');
        }
        if ($row['status'] === 'ARCHIVED') {
            throw new DomainException('Archived integration cannot be tested.');
        }

        $result = $this->healthProbe->probe(
            (string) $row['capability'],
            (string) $row['provider'],
            $this->decodeJson($row['config'] ?? null),
            is_string($row['credentials_reference']) ? $row['credentials_reference'] : null,
        );
        $status = strtoupper((string) ($result['status'] ?? 'UNKNOWN'));
        if (!in_array($status, ['UNKNOWN', 'HEALTHY', 'DEGRADED', 'ERROR'], true)) {
            $status = 'ERROR';
        }
        $reason = $this->sanitizeReason($result['reason'] ?? null);

        $statement = $this->connection->prepare(
            'UPDATE cos_integrations
             SET health_status=:health_status,last_health_check_at=NOW(6),
                 last_success_at=CASE WHEN :health_status_success="HEALTHY" THEN NOW(6) ELSE last_success_at END,
                 last_error=:last_error,updated_at=updated_at
             WHERE organization_id=:organization_id AND id=:id'
        );
        $statement->execute([
            'health_status' => $status,
            'health_status_success' => $status,
            'last_error' => $status === 'HEALTHY' ? null : $reason,
            'organization_id' => $organizationId,
            'id' => $integrationId,
        ]);

        return [
            'integration_id' => $integrationId,
            'health_status' => $status,
            'reason' => $status === 'HEALTHY' ? null : $reason,
        ];
    }

    public function saveRoute(string $organizationId, int $integrationId, array $input, string $actorId): array
    {
        if ($this->integration($organizationId, $integrationId) === null) {
            throw new DomainException('Sales integration was not found.');
        }

        $routeId = trim((string) ($input['id'] ?? ''));
        $current = $routeId !== '' ? $this->route($organizationId, $integrationId, $routeId) : null;
        if ($routeId !== '' && $current === null) {
            throw new DomainException('Integration route was not found.');
        }

        $status = strtoupper(trim((string) ($input['status'] ?? ($current['status'] ?? 'ACTIVE'))));
        $strategy = strtoupper(trim((string) ($input['assignment_strategy'] ?? ($current['assignment_strategy'] ?? 'KEEP_UNASSIGNED'))));
        if (!in_array($status, ['ACTIVE', 'DISABLED', 'ARCHIVED'], true)
            || !in_array($strategy, ['KEEP_UNASSIGNED', 'TEAM_DEFAULT', 'ROUND_ROBIN'], true)) {
            throw new DomainException('Invalid integration route configuration.');
        }

        $pipelineId = $this->nullableId($input['pipeline_id'] ?? ($current['pipeline_id'] ?? null));
        $stageId = $this->nullableId($input['initial_stage_id'] ?? ($current['initial_stage_id'] ?? null));
        $teamId = $this->nullableId($input['team_id'] ?? ($current['team_id'] ?? null));
        $source = mb_substr(trim((string) ($input['inbound_source'] ?? ($current['inbound_source'] ?? ''))), 0, 100);
        $source = $source === '' ? 'default' : $source;

        $this->validateRouteReferences($organizationId, $pipelineId, $stageId, $teamId);

        if ($current === null) {
            $routeId = bin2hex(random_bytes(16));
            $statement = $this->connection->prepare(
                'INSERT INTO sales_integration_routes
                 (id,organization_id,integration_id,inbound_source,pipeline_id,initial_stage_id,team_id,assignment_strategy,status,
                  configuration_version,created_by,updated_by,created_at,updated_at)
                 VALUES (:id,:organization_id,:integration_id,:inbound_source,:pipeline_id,:initial_stage_id,:team_id,:assignment_strategy,:status,
                         1,:actor_id,:actor_id,NOW(6),NOW(6))'
            );
            $statement->execute([
                'id'=>$routeId,'organization_id'=>$organizationId,'integration_id'=>$integrationId,'inbound_source'=>$source,
                'pipeline_id'=>$pipelineId,'initial_stage_id'=>$stageId,'team_id'=>$teamId,'assignment_strategy'=>$strategy,
                'status'=>$status,'actor_id'=>$actorId,
            ]);
            $after = $this->route($organizationId, $integrationId, $routeId) ?? [];
            $this->revision($organizationId, 'INTEGRATION_ROUTE', 'integration-route:' . $routeId, 1, 'CREATE', $actorId, null, $after);
            return $after;
        }

        $expectedVersion = (int) ($input['configuration_version'] ?? 0);
        if ($expectedVersion <= 0 || (int) $current['configuration_version'] !== $expectedVersion) {
            throw new DomainException('CONFIGURATION_CONFLICT');
        }
        $version = $expectedVersion + 1;
        $statement = $this->connection->prepare(
            'UPDATE sales_integration_routes
             SET inbound_source=:inbound_source,pipeline_id=:pipeline_id,initial_stage_id=:initial_stage_id,team_id=:team_id,
                 assignment_strategy=:assignment_strategy,status=:status,configuration_version=:next_version,
                 updated_by=:actor_id,updated_at=NOW(6)
             WHERE organization_id=:organization_id AND integration_id=:integration_id AND id=:id
               AND configuration_version=:expected_version'
        );
        $statement->execute([
            'inbound_source'=>$source,'pipeline_id'=>$pipelineId,'initial_stage_id'=>$stageId,'team_id'=>$teamId,
            'assignment_strategy'=>$strategy,'status'=>$status,'next_version'=>$version,'actor_id'=>$actorId,
            'organization_id'=>$organizationId,'integration_id'=>$integrationId,'id'=>$routeId,'expected_version'=>$expectedVersion,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new DomainException('CONFIGURATION_CONFLICT');
        }
        $after = $this->route($organizationId, $integrationId, $routeId) ?? [];
        $this->revision(
            $organizationId,
            'INTEGRATION_ROUTE',
            'integration-route:' . $routeId,
            $version,
            $status === 'ARCHIVED' ? 'ARCHIVE' : ($status === 'DISABLED' ? 'DISABLE' : 'UPDATE'),
            $actorId,
            $current,
            $after,
        );
        return $after;
    }

    public function revisions(string $organizationId, int $integrationId, int $limit = 100): array
    {
        $statement = $this->connection->prepare(
            'SELECT id,configuration_type,entity_id,entity_version,action,actor_type,actor_id,reason,before_payload,after_payload,created_at
             FROM cos_configuration_revisions
             WHERE organization_id=:organization_id AND domain_name="sales" AND entity_id=:entity_id
               AND configuration_type="INTEGRATION"
             ORDER BY id DESC LIMIT :limit'
        );
        $statement->bindValue(':organization_id', $organizationId);
        $statement->bindValue(':entity_id', 'integration:' . $integrationId);
        $statement->bindValue(':limit', max(1, min(200, $limit)), PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function routes(string $organizationId, int $integrationId): array
    {
        $statement = $this->connection->prepare(
            'SELECT id,integration_id,inbound_source,pipeline_id,initial_stage_id,team_id,assignment_strategy,status,
                    configuration_version,created_by,updated_by,created_at,updated_at
             FROM sales_integration_routes
             WHERE organization_id=:organization_id AND integration_id=:integration_id
             ORDER BY status="ACTIVE" DESC,inbound_source,id'
        );
        $statement->execute(['organization_id'=>$organizationId,'integration_id'=>$integrationId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function route(string $organizationId, int $integrationId, string $routeId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id,integration_id,inbound_source,pipeline_id,initial_stage_id,team_id,assignment_strategy,status,
                    configuration_version,created_by,updated_by,created_at,updated_at
             FROM sales_integration_routes
             WHERE organization_id=:organization_id AND integration_id=:integration_id AND id=:id LIMIT 1'
        );
        $statement->execute(['organization_id'=>$organizationId,'integration_id'=>$integrationId,'id'=>$routeId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function routeCount(string $organizationId, int $integrationId): int
    {
        $statement = $this->connection->prepare(
            'SELECT COUNT(*) FROM sales_integration_routes
             WHERE organization_id=:organization_id AND integration_id=:integration_id AND status<>"ARCHIVED"'
        );
        $statement->execute(['organization_id'=>$organizationId,'integration_id'=>$integrationId]);
        return (int) $statement->fetchColumn();
    }

    private function validateRouteReferences(string $organizationId, ?string $pipelineId, ?string $stageId, ?string $teamId): void
    {
        if ($pipelineId !== null) {
            $statement = $this->connection->prepare(
                'SELECT 1 FROM sales_pipelines WHERE organization_id=:organization_id AND id=:id AND status<>"ARCHIVED" LIMIT 1'
            );
            $statement->execute(['organization_id'=>$organizationId,'id'=>$pipelineId]);
            if ($statement->fetchColumn() === false) {
                throw new DomainException('Integration route pipeline is invalid.');
            }
        }
        if ($stageId !== null) {
            if ($pipelineId === null) {
                throw new DomainException('Initial stage requires a pipeline.');
            }
            $statement = $this->connection->prepare(
                'SELECT 1 FROM sales_pipeline_stages
                 WHERE organization_id=:organization_id AND pipeline_id=:pipeline_id AND id=:id AND status="ACTIVE" LIMIT 1'
            );
            $statement->execute(['organization_id'=>$organizationId,'pipeline_id'=>$pipelineId,'id'=>$stageId]);
            if ($statement->fetchColumn() === false) {
                throw new DomainException('Integration route initial stage is invalid.');
            }
        }
        if ($teamId !== null) {
            $statement = $this->connection->prepare(
                'SELECT 1 FROM sales_teams WHERE organization_id=:organization_id AND id=:id AND status="ACTIVE" LIMIT 1'
            );
            $statement->execute(['organization_id'=>$organizationId,'id'=>$teamId]);
            if ($statement->fetchColumn() === false) {
                throw new DomainException('Integration route team is invalid.');
            }
        }
    }

    private function publicIntegration(array $row): array
    {
        $credentialsReference = is_string($row['credentials_reference'] ?? null) ? $row['credentials_reference'] : null;
        unset($row['credentials_reference']);
        $row['config'] = $this->decodeJson($row['config'] ?? null);
        $row['credentials_configured'] = $credentialsReference !== null && $credentialsReference !== '';
        $row['credential_reference_scheme'] = $credentialsReference !== null && str_contains($credentialsReference, ':')
            ? strstr($credentialsReference, ':', true) . ':'
            : null;
        $row['last_error'] = $this->sanitizeReason($row['last_error'] ?? null);
        return $row;
    }

    private function revisionSnapshot(array $integration): array
    {
        return [
            'id' => $integration['id'] ?? null,
            'integration_key' => $integration['integration_key'] ?? null,
            'capability' => $integration['capability'] ?? null,
            'provider' => $integration['provider'] ?? null,
            'name' => $integration['name'] ?? null,
            'status' => $integration['status'] ?? null,
            'configuration_version' => $integration['configuration_version'] ?? null,
            'config' => $integration['config'] ?? [],
            'credentials_configured' => (bool) ($integration['credentials_configured'] ?? false),
        ];
    }

    private function credentialsReference(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $reference = trim((string) $value);
        if (!preg_match('/^env:[A-Z][A-Z0-9_]{2,126}$/', $reference)) {
            throw new DomainException('Credentials must be stored outside Sales and referenced as env:VARIABLE_NAME.');
        }
        return $reference;
    }

    private function nullableId(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $value)) {
            throw new DomainException('Invalid integration route reference.');
        }
        return $value;
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function sanitizeReason(mixed $reason): ?string
    {
        if (!is_string($reason) || trim($reason) === '') {
            return null;
        }
        $reason = preg_replace('/(token|secret|password|key)\s*[=:]\s*\S+/i', '$1=[redacted]', $reason) ?? $reason;
        return mb_substr(trim($reason), 0, 500);
    }

    private function revision(
        string $organizationId,
        string $type,
        string $entityId,
        int $version,
        string $action,
        string $actorId,
        ?array $before,
        array $after,
    ): void {
        $statement = $this->connection->prepare(
            'INSERT INTO cos_configuration_revisions
             (organization_id,domain_name,configuration_type,entity_id,entity_version,action,actor_type,actor_id,reason,before_payload,after_payload,created_at)
             VALUES (:organization_id,"sales",:type,:entity_id,:version,:action,"USER",:actor_id,NULL,:before_payload,:after_payload,NOW(6))'
        );
        $statement->execute([
            'organization_id'=>$organizationId,
            'type'=>$type,
            'entity_id'=>$entityId,
            'version'=>$version,
            'action'=>$action,
            'actor_id'=>$actorId,
            'before_payload'=>$before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_payload'=>json_encode($after, JSON_THROW_ON_ERROR),
        ]);
    }

    private function transactional(callable $callback): mixed
    {
        $owns = !$this->connection->inTransaction();
        if ($owns) {
            $this->connection->beginTransaction();
        }
        try {
            $result = $callback();
            if ($owns) {
                $this->connection->commit();
            }
            return $result;
        } catch (Throwable $error) {
            if ($owns && $this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $error;
        }
    }
}
