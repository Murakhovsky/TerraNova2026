<?php
declare(strict_types=1);

namespace Domains\Analytics\Infrastructure\Persistence\MySql;

use Domains\Property\Application\Contract\PropertyAnalyticsInterface;
use Infrastructure\Platform\Persistence\Pdo\PdoConnection;

final readonly class MysqlPropertyAnalytics implements PropertyAnalyticsInterface
{
    public function __construct(private PdoConnection $database) {}

    public function recordSubmission(int $submissionId, array $context): void
    {
        $this->database->connection()->prepare('
            INSERT INTO tn_analytics_events (
                event_type, entity_type, entity_id, source_page, utm_source, utm_medium, utm_campaign
            ) VALUES (
                "property_submit", "submission", :entity_id, :source_page, :utm_source, :utm_medium, :utm_campaign
            )
        ')->execute([
            'entity_id' => $submissionId,
            'source_page' => $this->nullable($context['source_page'] ?? null, 255),
            'utm_source' => $this->nullable($context['utm_source'] ?? null, 120),
            'utm_medium' => $this->nullable($context['utm_medium'] ?? null, 120),
            'utm_campaign' => $this->nullable($context['utm_campaign'] ?? null, 160),
        ]);
    }

    public function recordView(int $propertyId, array $context): void
    {
        $this->database->connection()->prepare('
            INSERT INTO tn_analytics_events (
                event_type, entity_type, entity_id, property_id, source_page,
                utm_source, utm_medium, utm_campaign, payload
            ) VALUES (
                "property_view", "property", :entity_id, :property_id, :source_page,
                :utm_source, :utm_medium, :utm_campaign, :payload
            )
        ')->execute([
            'entity_id' => $propertyId,
            'property_id' => $propertyId,
            'source_page' => $this->nullable($context['source_page'] ?? null, 255),
            'utm_source' => $this->nullable($context['utm_source'] ?? null, 120),
            'utm_medium' => $this->nullable($context['utm_medium'] ?? null, 120),
            'utm_campaign' => $this->nullable($context['utm_campaign'] ?? null, 160),
            'payload' => json_encode(['referer' => (string) ($context['referer'] ?? '')], JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function recordPresentation(string $eventType, ?int $propertyId, ?int $entityId, ?int $userId, string $sourcePage, array $payload): void
    {
        $this->database->connection()->prepare('
            INSERT INTO tn_analytics_events (
                event_type, entity_type, entity_id, property_id, user_id, source_page, payload
            ) VALUES (:event_type, :entity_type, :entity_id, :property_id, :user_id, :source_page, :payload)
        ')->execute([
            'event_type' => $eventType,
            'entity_type' => $propertyId ? 'property' : ($entityId ? 'client_case' : 'system'),
            'entity_id' => $propertyId ?: $entityId,
            'property_id' => $propertyId,
            'user_id' => $userId,
            'source_page' => mb_substr($sourcePage, 0, 255),
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function nullable(mixed $value, int $limit): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : mb_substr($value, 0, $limit);
    }
}
