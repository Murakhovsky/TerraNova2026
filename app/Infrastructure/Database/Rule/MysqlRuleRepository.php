<?php
declare(strict_types=1);

namespace Infrastructure\Database\Rule;

use Kernel\Rule\Contract\RuleRepositoryInterface;
use Kernel\Rule\Rule;
use PDO;

final readonly class MysqlRuleRepository implements RuleRepositoryInterface
{
    public function __construct(private PDO $connection) {}

    public function activeFor(string $organizationId, string $trigger): array
    {
        $statement = $this->connection->prepare(
            "SELECT * FROM cos_rules WHERE organization_id = :organization_id AND trigger_type = :trigger "
            . "AND status = 'ACTIVE' AND (valid_from IS NULL OR valid_from <= NOW()) "
            . "AND (valid_until IS NULL OR valid_until > NOW()) ORDER BY priority, version DESC"
        );
        $statement->execute(['organization_id' => $organizationId, 'trigger' => $trigger]);

        return array_map(static fn (array $row): Rule => new Rule(
            (string) $row['id'], (string) $row['organization_id'], (string) $row['name'],
            (string) $row['trigger_type'], self::json((string) $row['conditions']),
            self::json((string) $row['effect']), (int) $row['version'], (int) $row['priority'],
        ), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private static function json(string $json): array
    {
        $value = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        return is_array($value) ? $value : [];
    }
}
