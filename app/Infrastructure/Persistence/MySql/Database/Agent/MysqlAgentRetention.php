<?php
declare(strict_types=1);

namespace Infrastructure\Persistence\MySql\Database\Agent;

use Kernel\Agent\Contract\AgentRetentionInterface;
use PDO;

final readonly class MysqlAgentRetention implements AgentRetentionInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function purgeExpiredInputs(): int
    {
        $statement = $this->connection->prepare(
            'UPDATE cos_agent_runs SET input_snapshot = NULL '
            . 'WHERE input_expires_at IS NOT NULL AND input_expires_at <= NOW(6) AND input_snapshot IS NOT NULL'
        );
        $statement->execute();
        return $statement->rowCount();
    }
}
