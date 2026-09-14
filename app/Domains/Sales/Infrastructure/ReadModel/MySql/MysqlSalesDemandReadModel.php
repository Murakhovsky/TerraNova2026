<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\ReadModel\MySql;

use Domains\Sales\Application\Contract\SalesDemandReadModelInterface;
use PDO;

final readonly class MysqlSalesDemandReadModel implements SalesDemandReadModelInterface
{
    public function __construct(private PDO $connection) {}

    public function activePropertyInterests(string $organizationId): array
    {
        $statement = $this->connection->prepare('SELECT
                m.client_case_id,m.property_id,m.match_status,m.score
            FROM tn_client_case_property_matches m
            INNER JOIN tn_client_cases c
                ON c.id=m.client_case_id AND c.organization_id=m.organization_id
            WHERE m.organization_id=:organization_id
              AND c.status="active"
              AND m.match_status IN ("suggested","sent","interested","viewing","deal")
            ORDER BY m.client_case_id,m.updated_at DESC');
        $statement->execute(['organization_id' => $organizationId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $row): array => [
            'client_case_id' => (int) $row['client_case_id'],
            'property_id' => (int) $row['property_id'],
            'match_status' => (string) $row['match_status'],
            'score' => $row['score'],
        ], $rows);
    }

    public function coverage(string $organizationId): array
    {
        $statement = $this->connection->prepare('SELECT
                COUNT(*) AS active_cases,
                SUM(EXISTS(
                    SELECT 1 FROM tn_client_case_property_matches m
                    WHERE m.organization_id=c.organization_id
                      AND m.client_case_id=c.id
                      AND m.match_status IN ("suggested","sent","interested","viewing","deal")
                )) AS cases_with_property_matches
            FROM tn_client_cases c
            WHERE c.organization_id=:organization_id AND c.status="active"');
        $statement->execute(['organization_id' => $organizationId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'active_cases' => (int) ($row['active_cases'] ?? 0),
            'cases_with_property_matches' => (int) ($row['cases_with_property_matches'] ?? 0),
        ];
    }
}
