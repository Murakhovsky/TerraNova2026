<?php
declare(strict_types=1);

namespace Infrastructure\Persistence\MySql\Operations;

use Kernel\Operations\Contract\MetricsRecorderInterface;
use PDO;

final readonly class MysqlMetricsRecorder implements MetricsRecorderInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function record(string $metric, float $value, ?string $organizationId = null, array $labels = []): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO cos_operational_metrics (organization_id, metric, value, labels, recorded_at) '
            . 'VALUES (:organization_id, :metric, :value, :labels, NOW(6))'
        );
        $statement->execute([
            'organization_id' => $organizationId,
            'metric' => mb_substr($metric, 0, 160),
            'value' => $value,
            'labels' => $labels === [] ? null : json_encode($labels, JSON_THROW_ON_ERROR),
        ]);
    }
}
