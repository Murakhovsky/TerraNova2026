<?php
declare(strict_types=1);

namespace Modules\Frontend\Services;

use Common\Services\DatabaseService;
use Throwable;

class AnalyticsService
{
    private const PUBLIC_EVENTS = [
        'phone_click',
        'telegram_click',
        'viber_click',
        'presentation_request',
    ];

    public function __construct(private DatabaseService $database)
    {
    }

    public function recordPublicEvent(array $input, ?array $user = null): bool
    {
        $eventType = (string) ($input['event_type'] ?? '');
        if (!in_array($eventType, self::PUBLIC_EVENTS, true)) {
            return false;
        }

        $propertyId = max(0, (int) ($input['property_id'] ?? 0));
        if ($propertyId > 0 && !$this->database->fetchOne(
            'SELECT id FROM tn_properties WHERE id = :id AND status IN ("published", "active") LIMIT 1',
            ['id' => $propertyId]
        )) {
            $propertyId = 0;
        }

        try {
            $this->database->connection()->prepare('
                INSERT INTO tn_analytics_events (
                    event_type, entity_type, entity_id, property_id, user_id, source_page,
                    utm_source, utm_medium, utm_campaign, payload
                ) VALUES (
                    :event_type, :entity_type, :entity_id, :property_id, :user_id, :source_page,
                    :utm_source, :utm_medium, :utm_campaign, :payload
                )
            ')->execute([
                'event_type' => $eventType,
                'entity_type' => $propertyId > 0 ? 'property' : 'system',
                'entity_id' => $propertyId > 0 ? $propertyId : null,
                'property_id' => $propertyId > 0 ? $propertyId : null,
                'user_id' => !empty($user['id']) ? (int) $user['id'] : null,
                'source_page' => $this->nullable((string) ($input['source_page'] ?? ''), 255),
                'utm_source' => $this->nullable((string) ($input['utm_source'] ?? ''), 120),
                'utm_medium' => $this->nullable((string) ($input['utm_medium'] ?? ''), 120),
                'utm_campaign' => $this->nullable((string) ($input['utm_campaign'] ?? ''), 160),
                'payload' => json_encode([
                    'target' => mb_substr(trim((string) ($input['target'] ?? '')), 0, 255),
                    'label' => mb_substr(trim((string) ($input['label'] ?? '')), 0, 160),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function report(int $days = 30): array
    {
        $days = max(7, min(365, $days));
        $period = 'created_at >= DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)';

        $summary = $this->database->fetchOne('
            SELECT
                COUNT(*) AS total,
                SUM(event_type = "property_view") AS property_views,
                SUM(event_type = "phone_click") AS phone_clicks,
                SUM(event_type = "telegram_click") AS telegram_clicks,
                SUM(event_type = "viber_click") AS viber_clicks,
                SUM(event_type = "presentation_request") AS presentation_requests,
                SUM(event_type = "lead_submit") AS leads,
                SUM(event_type = "property_submit") AS property_submissions
            FROM tn_analytics_events
            WHERE ' . $period
        ) ?? [];

        $views = (int) ($summary['property_views'] ?? 0);
        $leads = (int) ($summary['leads'] ?? 0);
        $cta = (int) ($summary['phone_clicks'] ?? 0)
            + (int) ($summary['telegram_clicks'] ?? 0)
            + (int) ($summary['viber_clicks'] ?? 0)
            + (int) ($summary['presentation_requests'] ?? 0);

        return [
            'days' => $days,
            'summary' => [
                'total' => (int) ($summary['total'] ?? 0),
                'property_views' => $views,
                'phone_clicks' => (int) ($summary['phone_clicks'] ?? 0),
                'telegram_clicks' => (int) ($summary['telegram_clicks'] ?? 0),
                'viber_clicks' => (int) ($summary['viber_clicks'] ?? 0),
                'presentation_requests' => (int) ($summary['presentation_requests'] ?? 0),
                'leads' => $leads,
                'property_submissions' => (int) ($summary['property_submissions'] ?? 0),
                'cta' => $cta,
                'view_to_cta' => $views > 0 ? round($cta * 100 / $views, 1) : 0.0,
                'view_to_lead' => $views > 0 ? round($leads * 100 / $views, 1) : 0.0,
            ],
            'daily' => $this->database->fetchAll('
                SELECT DATE(created_at) AS event_date,
                       SUM(event_type = "property_view") AS property_views,
                       SUM(event_type IN ("phone_click", "telegram_click", "viber_click", "presentation_request")) AS cta,
                       SUM(event_type = "lead_submit") AS leads
                FROM tn_analytics_events
                WHERE ' . $period . '
                GROUP BY DATE(created_at)
                ORDER BY event_date DESC
                LIMIT 31
            '),
            'top_properties' => $this->database->fetchAll('
                SELECT p.id, p.public_id, p.slug, p.title,
                       SUM(e.event_type = "property_view") AS property_views,
                       SUM(e.event_type IN ("phone_click", "telegram_click", "viber_click", "presentation_request")) AS cta,
                       SUM(e.event_type = "lead_submit") AS leads
                FROM tn_analytics_events e
                INNER JOIN tn_properties p ON p.id = e.property_id
                WHERE ' . str_replace('created_at', 'e.created_at', $period) . '
                GROUP BY p.id
                ORDER BY leads DESC, cta DESC, property_views DESC
                LIMIT 12
            '),
            'sources' => $this->database->fetchAll('
                SELECT COALESCE(NULLIF(utm_source, ""), "direct") AS source,
                       COUNT(*) AS events,
                       SUM(event_type = "lead_submit") AS leads
                FROM tn_analytics_events
                WHERE ' . $period . '
                GROUP BY COALESCE(NULLIF(utm_source, ""), "direct")
                ORDER BY leads DESC, events DESC
                LIMIT 12
            '),
        ];
    }

    private function nullable(string $value, int $length): ?string
    {
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $length);
    }
}
