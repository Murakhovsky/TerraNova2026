<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\ReadModel\MySql;

use DateTimeImmutable;
use DomainException;
use PDO;
use Domains\Growth\Application\Contract\GrowthInboundResponseOutcomeReadModelInterface;

/** Persisted, de-duplicated inbound responses. This counts receipts of responses, not customer conversion. */
final readonly class MysqlGrowthInboundResponseOutcomeReadModel implements GrowthInboundResponseOutcomeReadModelInterface
{
    public function __construct(private PDO $connection) {}

    public function count(string $organizationId, DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        if ($organizationId === '' || $to <= $from) {
            throw new DomainException('Invalid growth.inbound_responses_recorded evidence scope.');
        }
        $query = $this->connection->prepare(
            "SELECT COUNT(*) FROM tn_growth_engagement_responses WHERE organization_id = :org AND created_at >= :from_time AND created_at < :to_time"
        );
        $query->execute([
            'org' => $organizationId,
            'from_time' => $from->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            'to_time' => $to->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
        ]);
        $raw = $query->fetchColumn();
        if (!is_scalar($raw) || !preg_match('/^[0-9]+$/', (string) $raw)) {
            throw new DomainException('Invalid Domain-owned aggregate count.');
        }
        return (int) $raw;
    }
}
