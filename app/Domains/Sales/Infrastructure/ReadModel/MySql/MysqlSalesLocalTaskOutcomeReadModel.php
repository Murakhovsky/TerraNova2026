<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\ReadModel\MySql;

use DateTimeImmutable;
use Domains\Sales\Application\Contract\SalesLocalTaskOutcomeReadModelInterface;
use DomainException;
use PDO;

/**
 * Measures persisted local CRM tasks. Explicitly tenant/time/type scoped,
 * using the canonical activity primary key as one task (no Action metrics).
 */
final readonly class MysqlSalesLocalTaskOutcomeReadModel implements SalesLocalTaskOutcomeReadModelInterface
{
    public function __construct(private PDO $connection) {}

    public function createdTaskCount(
        string $organizationId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): int {
        if ($organizationId === '' || $to <= $from) {
            throw new DomainException('Invalid local CRM task outcome scope.');
        }

        // MySQL TIMESTAMP has connection-timezone semantics. FROM_UNIXTIME()
        // converts UTC epoch to the same connection timezone for both bounds.
        $query = $this->connection->prepare(
            "SELECT COUNT(*) FROM tn_client_case_activities a
             WHERE a.organization_id = :org AND a.activity_type = 'task'
               AND a.created_at >= FROM_UNIXTIME(:from_epoch)
               AND a.created_at < FROM_UNIXTIME(:to_epoch)"
        );
        $query->bindValue(':org', $organizationId, PDO::PARAM_STR);
        // Preserve sub-second Run.created_at boundaries; integer seconds
        // would include tasks created immediately before the Run.
        $query->bindValue(':from_epoch', $from->format('U.u'), PDO::PARAM_STR);
        $query->bindValue(':to_epoch', $to->format('U.u'), PDO::PARAM_STR);
        $query->execute();
        $raw = $query->fetchColumn();
        if (!is_scalar($raw) || !preg_match('/^[0-9]+$/', (string) $raw)) {
            throw new DomainException('Local CRM task read model failed to return a valid count.');
        }
        return (int) $raw;
    }
}
