<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\ReadModel\MySql;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Domains\Growth\Application\Contract\GrowthRunLinkedResponseReadModelInterface;
use PDO;

/**
 * Domain-owned projection. Only Action IDs attested by Federation may reach it.
 * An empty eligible Action set is zero, never the count of all replies.
 */
final readonly class MysqlGrowthRunLinkedResponseReadModel implements GrowthRunLinkedResponseReadModelInterface
{
    public function __construct(private PDO $connection) {}

    public function countForActions(
        string $organizationId,
        array $actionIds,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): int {
        if ($organizationId === '' || $to <= $from) {
            throw new DomainException('Invalid Run-linked Growth evidence scope.');
        }
        if ($actionIds === []) return 0;
        $unique = array_values(array_unique($actionIds));
        if (count($unique) > 1000) {
            throw new DomainException('Excessive Run-linked Action references.');
        }
        $params = [
            'org' => $organizationId,
            'from_time' => $from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            'to_time' => $to->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
        ];
        $placeholders = [];
        foreach ($unique as $i => $id) {
            if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) {
                throw new DomainException('Invalid attested canonical Action ID.');
            }
            $key = 'action' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }
        $statement = $this->connection->prepare(
            'SELECT COUNT(DISTINCT response_id) FROM tn_growth_engagement_responses
             WHERE organization_id = :org AND created_at >= :from_time
               AND created_at < :to_time
               AND action_id IN (' . implode(',', $placeholders) . ')'
        );
        $statement->execute($params);
        $value = $statement->fetchColumn();
        if (!is_scalar($value) || !preg_match('/^[0-9]+$/D', (string) $value)) {
            throw new DomainException('Invalid recorded Growth reply count.');
        }
        return (int) $value;
    }
}
