<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\Persistence\MySql;

use DateTimeImmutable;
use DomainException;
use PDO;
use Domains\CapitalMarkets\Application\Contract\CapitalResearchValidatedOutcomeReadModelInterface;

/** Persisted ResearchResultStatus::Validated records, not profitable strategies or approved trading. */
final readonly class MysqlCapitalResearchValidatedOutcomeReadModel implements CapitalResearchValidatedOutcomeReadModelInterface
{
    public function __construct(private PDO $connection) {}

    public function count(string $organizationId, DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        if ($organizationId === '' || $to <= $from) {
            throw new DomainException('Invalid capital_markets.research_results_validated evidence scope.');
        }
        $query = $this->connection->prepare(
            "SELECT COUNT(*) FROM tn_capital_market_research_results WHERE organization_id = :org AND status = 'VALIDATED' AND created_at >= :from_time AND created_at < :to_time"
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
