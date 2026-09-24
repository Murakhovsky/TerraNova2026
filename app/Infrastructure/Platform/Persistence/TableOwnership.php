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
        'RealEstate' => [
            'tn_real_estate_cases', 'tn_real_estate_offers', 'tn_real_estate_showings',
            'tn_real_estate_operation_receipts',
        ],
        'Service' => [
            'tn_service_cases', 'tn_service_requests', 'tn_service_tickets', 'tn_service_assignments',
            'tn_service_slas', 'tn_service_escalations', 'tn_service_resolutions',
            'tn_service_operation_receipts',
        ],
        'Growth' => [
            'tn_growth_signals', 'tn_growth_candidates', 'tn_growth_candidate_signals',
            'tn_growth_operation_receipts', 'tn_growth_icp_profiles', 'tn_growth_accounts',
            'tn_growth_account_snapshots', 'tn_growth_account_icp_matches',
            'tn_growth_contacts', 'tn_growth_account_contacts', 'tn_growth_contact_snapshots',
            'tn_growth_buying_committee_assessments',
            'tn_growth_signal_collector_runs', 'tn_growth_signal_source_receipts', 'tn_growth_signal_feeds',
            'tn_growth_signal_collector_health', 'tn_growth_signal_collector_incidents',
            'tn_growth_collector_alert_subscriptions',
            'tn_growth_qualification_policies', 'tn_growth_candidate_evaluations',
            'tn_growth_research_runs', 'tn_growth_research_proposals',
            'tn_growth_handoff_attempts',
            'tn_growth_engagement_runs', 'tn_growth_engagement_recommendations', 'tn_growth_engagement_execution_links',
            'tn_growth_engagement_delivery_observations',
            'tn_growth_learning_bindings', 'tn_growth_outcomes',
            'tn_growth_optimization_runs', 'tn_growth_optimization_recommendations',
            'tn_growth_experiments', 'tn_growth_experiment_assignments',
            'tn_growth_experiment_decision_runs', 'tn_growth_experiment_decision_recommendations',
        ],
        'Content' => ['tn_content_items', 'tn_content_revisions'],
        'Diagnostic' => ['diagnostic_packs', 'diagnostic_sessions', 'diagnostic_evidence', 'diagnostic_records'],
        'Spatial' => [
            'tn_spatial_assets', 'tn_spatial_captures', 'tn_spatial_events', 'tn_spatial_hotspots',
            'tn_spatial_processing_jobs', 'tn_spatial_relations', 'tn_spatial_scenes', 'tn_spatial_versions',
        ],
        'Media' => ['tn_media_assets', 'tn_media_relations'],
        'Reference' => ['tn_locations'],
        'Platform' => [
            'cos_events', 'cos_event_outbox', 'cos_event_consumptions', 'cos_actions',
            'cos_action_attempts', 'cos_approvals', 'cos_policies', 'cos_policy_evaluations',
            'cos_rules', 'cos_rule_evaluations', 'cos_agent_runs', 'cos_audit_log', 'cos_decisions',
            'cos_jobs', 'cos_configuration_provisions', 'cos_operational_metrics', 'cos_crm_inbox',
            'cos_external_references', 'cos_integrations', 'cos_sync_state', 'cos_llm_budgets', 'cos_llm_usage',
            'cos_documents', 'cos_document_files', 'cos_document_versions', 'cos_document_relations',
            'cos_feature_flags', 'cos_feature_flag_overrides', 'cos_notification_deliveries',
            'cos_document_templates', 'cos_document_signatures', 'cos_document_operation_receipts',
            'tn_notification_outbox', 'tn_analytics_events',
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
