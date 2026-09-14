<?php
declare(strict_types=1);

namespace Infrastructure\Platform\Persistence\MySql;

use Domains\Content\Application\Contract\ContentIntegrationOutboxInterface;
use Infrastructure\Platform\Persistence\Pdo\PdoConnection;

final readonly class MysqlContentIntegrationOutbox implements ContentIntegrationOutboxInterface
{
    public function __construct(private PdoConnection $database) {}

    public function stats(string $integration): array
    {
        $rows = $this->database->fetchAll(
            'SELECT status, COUNT(*) AS total FROM tn_integration_outbox WHERE integration = :integration GROUP BY status',
            ['integration' => $integration],
        );
        $stats = ['pending' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($rows as $row) $stats[(string) $row['status']] = (int) $row['total'];
        return $stats;
    }

    public function enqueue(string $integration, string $eventType, string $entityType, int $entityId, array $payload): void
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->database->connection()->prepare('
            INSERT IGNORE INTO tn_integration_outbox (
                integration, event_type, entity_type, entity_id, payload, dedupe_key
            ) VALUES (:integration, :event_type, :entity_type, :entity_id, :payload, :dedupe_key)
        ')->execute([
            'integration' => $integration, 'event_type' => $eventType, 'entity_type' => $entityType,
            'entity_id' => $entityId, 'payload' => $encoded,
            'dedupe_key' => $integration . ':' . $entityType . ':' . $entityId . ':' . hash('sha256', $encoded),
        ]);
    }
}
