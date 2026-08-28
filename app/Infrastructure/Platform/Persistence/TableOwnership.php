<?php
declare(strict_types=1);

namespace Infrastructure\Platform\Persistence;

final class TableOwnership
{
    /** @var array<string, list<string>> */
    public const TABLES = [
        'Identity' => [
            'tn_users', 'cos_organizations', 'cos_organization_memberships',
            'tn_telegram_bindings', 'tn_telegram_link_tokens',
            'company_companies', 'company_employees', 'msg_system_messages', 'msg_user_messages',
            'person_users', 'person_cold_phones', 'person_contacts', 'person_contacts_re',
            'person_users_likes', 'person_users_progress', 'service_lists', 'service_lists_items',
            'service_reminders', 'service_settings',
        ],
        'Property' => [
            'tn_agents', 'tn_properties', 'tn_property_activities', 'tn_property_features',
            'tn_property_groups', 'tn_property_images', 'tn_property_submissions', 'tn_property_types',
            'estate_objects', 'estate_archive', 'estate_adverts', 'estate_objects_disabled',
        ],
        'Sales' => [
            'tn_people', 'tn_leads', 'tn_lead_activities', 'tn_client_cases',
            'tn_client_case_activities', 'tn_client_case_property_matches',
            'tn_client_case_request_matches', 'tn_buyer_activities', 'tn_buyer_matches',
            'tn_buyer_requests', 'tn_buyers', 'request_requests', 'request_shows', 'request_join_realty',
        ],
        'Content' => ['tn_content_items', 'tn_content_revisions'],
        'Spatial' => [
            'tn_spatial_assets', 'tn_spatial_captures', 'tn_spatial_events', 'tn_spatial_hotspots',
            'tn_spatial_processing_jobs', 'tn_spatial_relations', 'tn_spatial_scenes', 'tn_spatial_versions',
        ],
        'Analytics' => ['tn_analytics_events'],
        'Media' => ['tn_media_assets', 'tn_media_relations'],
        'Reference' => ['tn_locations'],
        'Platform' => [
            'cos_events', 'cos_event_outbox', 'cos_event_consumptions', 'cos_actions',
            'cos_action_attempts', 'cos_approvals', 'cos_policies', 'cos_policy_evaluations',
            'cos_rules', 'cos_rule_evaluations', 'cos_agent_runs', 'cos_audit_log', 'cos_decisions',
            'cos_jobs', 'cos_configuration_provisions', 'cos_operational_metrics', 'cos_crm_inbox',
            'cos_external_references', 'cos_integrations', 'cos_sync_state', 'tn_notification_outbox',
            'tn_integration_outbox', 'tn_webhook_deliveries', 'tn_migrations',
        ],
    ];

    public static function ownerOf(string $table): ?string
    {
        $table = strtolower(trim($table, "` \t\n\r\0\x0B"));
        foreach (self::TABLES as $owner => $tables) {
            if (in_array($table, $tables, true)) return $owner;
        }

        return null;
    }

    public static function isOwnedBy(string $table, string $owner): bool
    {
        return self::ownerOf($table) === $owner;
    }
}
