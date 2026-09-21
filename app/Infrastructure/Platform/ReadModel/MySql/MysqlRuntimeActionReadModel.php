<?php
declare(strict_types=1);

namespace Infrastructure\Platform\ReadModel\MySql;

use DateTimeImmutable;
use Kernel\Action\ActionStatus;
use Kernel\Action\Contract\RuntimeActionReadModelInterface;
use Kernel\Action\RuntimeActionProjection;
use PDO;

final readonly class MysqlRuntimeActionReadModel implements RuntimeActionReadModelInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function forEntity(
        string $organizationId,
        array $targetTypes,
        string $targetId,
        int $limit = 20,
    ): array {
        $organizationId = trim($organizationId);
        $targetId = trim($targetId);
        $targetTypes = array_values(array_unique(array_filter(
            array_map(static fn (mixed $type): string => trim((string) $type), $targetTypes),
            static fn (string $type): bool => $type !== '',
        )));

        if ($organizationId === '' || $targetId === '' || $targetTypes === []) {
            return [];
        }

        $limit = max(1, min(100, $limit));
        $placeholders = [];
        $params = [
            'organization_id' => $organizationId,
            'target_id' => $targetId,
        ];

        foreach ($targetTypes as $index => $targetType) {
            $key = 'target_type_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $targetType;
        }

        $statement = $this->connection->prepare(
            'SELECT a.id,a.organization_id,a.type,a.target_type,a.target_id,a.source_type,a.source_id,'
            . 'a.status,a.execution_mode,a.risk_level,a.created_at,'
            . 'ap.id approval_id,ap.status approval_status '
            . 'FROM cos_actions a '
            . 'LEFT JOIN cos_approvals ap ON ap.id=('
            . 'SELECT cap.id FROM cos_approvals cap '
            . 'WHERE cap.organization_id=a.organization_id AND cap.action_id=a.id '
            . 'ORDER BY cap.created_at DESC LIMIT 1'
            . ') '
            . 'WHERE a.organization_id=:organization_id '
            . 'AND a.target_id=:target_id '
            . 'AND a.target_type IN (' . implode(',', $placeholders) . ') '
            . 'ORDER BY a.created_at DESC LIMIT ' . $limit,
        );
        $statement->execute($params);

        $items = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (string) ($row['id'] ?? '');
            if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
                continue;
            }

            $status = ActionStatus::tryFrom((string) ($row['status'] ?? ''));
            if ($status === null) {
                continue;
            }

            $createdAt = new DateTimeImmutable((string) ($row['created_at'] ?? 'now'));

            $items[] = new RuntimeActionProjection(
                id: $id,
                organizationId: (string) $row['organization_id'],
                type: (string) $row['type'],
                targetType: $this->nullable($row['target_type'] ?? null),
                targetId: $this->nullable($row['target_id'] ?? null),
                sourceType: (string) ($row['source_type'] ?? ''),
                sourceId: (string) ($row['source_id'] ?? ''),
                status: $status,
                executionMode: (string) ($row['execution_mode'] ?? 'MANUAL'),
                riskLevel: (string) ($row['risk_level'] ?? 'LOW'),
                createdAt: $createdAt,
                approvalId: $this->nullable($row['approval_id'] ?? null),
                approvalStatus: $this->nullable($row['approval_status'] ?? null),
            );
        }

        return $items;
    }

    private function nullable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
