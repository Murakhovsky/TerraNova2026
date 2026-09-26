<?php
declare(strict_types=1);

namespace Domains\Growth\Automation\Event;

final class GrowthEventType
{
    public const ICP_DRAFTED = 'growth.icp.drafted';
    public const ICP_REVISED = 'growth.icp.revised';
    public const ICP_ACTIVATED = 'growth.icp.activated';
    public const ACCOUNT_DISCOVERED = 'growth.account.discovered';
    public const ACCOUNT_SNAPSHOT_CAPTURED = 'growth.account.snapshot_captured';
    public const ACCOUNT_ICP_SCORED = 'growth.account.icp_scored';
    public const CONTACT_DISCOVERED = 'growth.contact.discovered';
    public const CONTACT_SNAPSHOT_CAPTURED = 'growth.contact.snapshot_captured';
    public const BUYING_COMMITTEE_ASSESSED = 'growth.buying_committee.assessed';
    public const COLLECTOR_RUN_STARTED = 'growth.collector.run_started';
    public const COLLECTOR_RUN_COMPLETED = 'growth.collector.run_completed';
    public const COLLECTOR_RUN_FAILED = 'growth.collector.run_failed';
    public const COLLECTOR_INCIDENT_OPENED = 'growth.collector.incident_opened';
    public const COLLECTOR_INCIDENT_RESOLVED = 'growth.collector.incident_resolved';
    public const COLLECTOR_ALERT_SUBSCRIPTION_CREATED = 'growth.collector.alert_subscription_created';
    public const COLLECTOR_ALERT_SUBSCRIPTION_ENABLED = 'growth.collector.alert_subscription_enabled';
    public const COLLECTOR_ALERT_SUBSCRIPTION_DISABLED = 'growth.collector.alert_subscription_disabled';
    public const SIGNAL_FEED_CREATED = 'growth.signal_feed.created';
    public const SIGNAL_FEED_ENABLED = 'growth.signal_feed.enabled';
    public const SIGNAL_FEED_DISABLED = 'growth.signal_feed.disabled';
    public const SIGNAL_JSON_SOURCE_CREATED = 'growth.signal_json_source.created';
    public const SIGNAL_JSON_SOURCE_ENABLED = 'growth.signal_json_source.enabled';
    public const SIGNAL_JSON_SOURCE_DISABLED = 'growth.signal_json_source.disabled';
    public const QUALIFICATION_POLICY_DRAFTED = 'growth.qualification_policy.drafted';
    public const QUALIFICATION_POLICY_REVISED = 'growth.qualification_policy.revised';
    public const QUALIFICATION_POLICY_ACTIVATED = 'growth.qualification_policy.activated';
    public const CANDIDATE_EVALUATED = 'growth.candidate.evaluated';
    public const RESEARCH_RUN_STARTED = 'growth.research.run_started';
    public const RESEARCH_RUN_COMPLETED = 'growth.research.run_completed';
    public const RESEARCH_RUN_FAILED = 'growth.research.run_failed';
    public const RESEARCH_PROPOSAL_CREATED = 'growth.research.proposal_created';
    public const RESEARCH_PROPOSAL_ACCEPTED = 'growth.research.proposal_accepted';
    public const HANDOFF_DISPATCH_STARTED = 'growth.handoff.dispatch_started';
    public const HANDOFF_DISPATCH_FAILED = 'growth.handoff.dispatch_failed';
    public const HANDOFF_ACCEPTED = 'growth.handoff.accepted';
    public const HANDOFF_REJECTED = 'growth.handoff.rejected';
    public const ENGAGEMENT_RUN_STARTED = 'growth.engagement.run_started';
    public const ENGAGEMENT_RUN_COMPLETED = 'growth.engagement.run_completed';
    public const ENGAGEMENT_RUN_FAILED = 'growth.engagement.run_failed';
    public const ENGAGEMENT_RECOMMENDATION_CREATED = 'growth.engagement.recommendation_created';
    public const ENGAGEMENT_RECOMMENDATION_ACCEPTED = 'growth.engagement.recommendation_accepted';
    public const ENGAGEMENT_RECOMMENDATION_DISMISSED = 'growth.engagement.recommendation_dismissed';
    public const ENGAGEMENT_RECOMMENDATION_SUPERSEDED = 'growth.engagement.recommendation_superseded';
    public const ENGAGEMENT_EXECUTION_PROPOSED = 'growth.engagement.execution_proposed';
    public const ENGAGEMENT_DELIVERY_OBSERVED = 'growth.engagement.delivery_observed';
    public const ENGAGEMENT_LIMIT_PROFILE_UPDATED = 'growth.engagement.limit_profile_updated';
    public const ENGAGEMENT_ACTIVATION_PROFILE_UPDATED = 'growth.engagement.activation_profile_updated';
    public const ENGAGEMENT_AUTONOMY_PROFILE_UPDATED = 'growth.engagement.autonomy_profile_updated';
    public const ENGAGEMENT_AUTONOMY_PAYLOAD_STAGED = 'growth.engagement.autonomy_payload_staged';
    public const ENGAGEMENT_AUTONOMOUS_TRIGGERED = 'growth.engagement.autonomous_triggered';
    public const ENGAGEMENT_CONTENT_REVIEW_PROFILE_UPDATED = 'growth.engagement.content_review_profile_updated';
    public const ENGAGEMENT_CONTENT_RUN_STARTED = 'growth.engagement.content_run_started';
    public const ENGAGEMENT_CONTENT_RUN_COMPLETED = 'growth.engagement.content_run_completed';
    public const ENGAGEMENT_CONTENT_RUN_FAILED = 'growth.engagement.content_run_failed';
    public const ENGAGEMENT_CONTENT_DRAFT_CREATED = 'growth.engagement.content_draft_created';
    public const ENGAGEMENT_CONTENT_DRAFT_APPROVED = 'growth.engagement.content_draft_approved';
    public const ENGAGEMENT_CONTENT_DRAFT_REJECTED = 'growth.engagement.content_draft_rejected';
    public const ENGAGEMENT_CONTENT_DRAFT_BLOCKED = 'growth.engagement.content_draft_blocked';
    public const LEARNING_BINDING_CREATED = 'growth.learning.binding_created';
    public const OUTCOME_RECORDED = 'growth.outcome.recorded';
    public const EXPERIMENT_DRAFTED = 'growth.experiment.drafted';
    public const EXPERIMENT_STARTED = 'growth.experiment.started';
    public const EXPERIMENT_PAUSED = 'growth.experiment.paused';
    public const EXPERIMENT_RESUMED = 'growth.experiment.resumed';
    public const EXPERIMENT_COMPLETED = 'growth.experiment.completed';
    public const EXPERIMENT_ARCHIVED = 'growth.experiment.archived';
    public const EXPERIMENT_CANDIDATE_ASSIGNED = 'growth.experiment.candidate_assigned';
    public const EXPERIMENT_DECISION_RUN_STARTED = 'growth.experiment_decision.run_started';
    public const EXPERIMENT_DECISION_RUN_COMPLETED = 'growth.experiment_decision.run_completed';
    public const EXPERIMENT_DECISION_RUN_FAILED = 'growth.experiment_decision.run_failed';
    public const EXPERIMENT_DECISION_RECOMMENDATION_CREATED = 'growth.experiment_decision.recommendation_created';
    public const EXPERIMENT_DECISION_RECOMMENDATION_ACCEPTED = 'growth.experiment_decision.recommendation_accepted';
    public const EXPERIMENT_DECISION_RECOMMENDATION_DISMISSED = 'growth.experiment_decision.recommendation_dismissed';
    public const EXPERIMENT_DECISION_RECOMMENDATION_SUPERSEDED = 'growth.experiment_decision.recommendation_superseded';
    public const OPTIMIZATION_RUN_STARTED = 'growth.optimization.run_started';
    public const OPTIMIZATION_RUN_COMPLETED = 'growth.optimization.run_completed';
    public const OPTIMIZATION_RUN_FAILED = 'growth.optimization.run_failed';
    public const OPTIMIZATION_RECOMMENDATION_CREATED = 'growth.optimization.recommendation_created';
    public const OPTIMIZATION_RECOMMENDATION_ACCEPTED = 'growth.optimization.recommendation_accepted';
    public const OPTIMIZATION_RECOMMENDATION_DISMISSED = 'growth.optimization.recommendation_dismissed';
    public const OPTIMIZATION_RECOMMENDATION_MATERIALIZED = 'growth.optimization.recommendation_materialized';
    public const OPTIMIZATION_RECOMMENDATION_STALE = 'growth.optimization.recommendation_stale';
    public const OPTIMIZATION_RECOMMENDATION_SUPERSEDED = 'growth.optimization.recommendation_superseded';

    public const SIGNAL_DETECTED = 'growth.signal.detected';
    public const CANDIDATE_DETECTED = 'growth.candidate.detected';
    public const CANDIDATE_RESEARCHED = 'growth.candidate.researched';
    public const CANDIDATE_SCORED = 'growth.candidate.scored';
    public const CANDIDATE_QUALIFIED = 'growth.candidate.qualified';
    public const CANDIDATE_MONITORING_STARTED = 'growth.candidate.monitoring_started';
    public const CANDIDATE_DISQUALIFIED = 'growth.candidate.disqualified';
    public const HANDOFF_PREPARED = 'growth.handoff.prepared';

    /** @return list<string> */
    public static function values(): array
    {
        return [
            self::ICP_DRAFTED,
            self::ICP_REVISED,
            self::ICP_ACTIVATED,
            self::ACCOUNT_DISCOVERED,
            self::ACCOUNT_SNAPSHOT_CAPTURED,
            self::ACCOUNT_ICP_SCORED,
            self::CONTACT_DISCOVERED,
            self::CONTACT_SNAPSHOT_CAPTURED,
            self::BUYING_COMMITTEE_ASSESSED,
            self::COLLECTOR_RUN_STARTED,
            self::COLLECTOR_RUN_COMPLETED,
            self::COLLECTOR_RUN_FAILED,
            self::COLLECTOR_INCIDENT_OPENED,
            self::COLLECTOR_INCIDENT_RESOLVED,
            self::COLLECTOR_ALERT_SUBSCRIPTION_CREATED,
            self::COLLECTOR_ALERT_SUBSCRIPTION_ENABLED,
            self::COLLECTOR_ALERT_SUBSCRIPTION_DISABLED,
            self::SIGNAL_FEED_CREATED,
            self::SIGNAL_FEED_ENABLED,
            self::SIGNAL_FEED_DISABLED,
            self::SIGNAL_JSON_SOURCE_CREATED,
            self::SIGNAL_JSON_SOURCE_ENABLED,
            self::SIGNAL_JSON_SOURCE_DISABLED,
            self::QUALIFICATION_POLICY_DRAFTED,
            self::QUALIFICATION_POLICY_REVISED,
            self::QUALIFICATION_POLICY_ACTIVATED,
            self::CANDIDATE_EVALUATED,
            self::RESEARCH_RUN_STARTED,
            self::RESEARCH_RUN_COMPLETED,
            self::RESEARCH_RUN_FAILED,
            self::RESEARCH_PROPOSAL_CREATED,
            self::RESEARCH_PROPOSAL_ACCEPTED,
            self::HANDOFF_DISPATCH_STARTED,
            self::HANDOFF_DISPATCH_FAILED,
            self::HANDOFF_ACCEPTED,
            self::HANDOFF_REJECTED,
            self::ENGAGEMENT_RUN_STARTED,
            self::ENGAGEMENT_RUN_COMPLETED,
            self::ENGAGEMENT_RUN_FAILED,
            self::ENGAGEMENT_RECOMMENDATION_CREATED,
            self::ENGAGEMENT_RECOMMENDATION_ACCEPTED,
            self::ENGAGEMENT_RECOMMENDATION_DISMISSED,
            self::ENGAGEMENT_RECOMMENDATION_SUPERSEDED,
            self::ENGAGEMENT_EXECUTION_PROPOSED,
            self::ENGAGEMENT_DELIVERY_OBSERVED,
            self::ENGAGEMENT_LIMIT_PROFILE_UPDATED,
            self::ENGAGEMENT_ACTIVATION_PROFILE_UPDATED,
            self::ENGAGEMENT_AUTONOMY_PROFILE_UPDATED,
            self::ENGAGEMENT_AUTONOMY_PAYLOAD_STAGED,
            self::ENGAGEMENT_AUTONOMOUS_TRIGGERED,
            self::ENGAGEMENT_CONTENT_REVIEW_PROFILE_UPDATED,
            self::ENGAGEMENT_CONTENT_RUN_STARTED,
            self::ENGAGEMENT_CONTENT_RUN_COMPLETED,
            self::ENGAGEMENT_CONTENT_RUN_FAILED,
            self::ENGAGEMENT_CONTENT_DRAFT_CREATED,
            self::ENGAGEMENT_CONTENT_DRAFT_APPROVED,
            self::ENGAGEMENT_CONTENT_DRAFT_REJECTED,
            self::ENGAGEMENT_CONTENT_DRAFT_BLOCKED,
            self::LEARNING_BINDING_CREATED,
            self::OUTCOME_RECORDED,
            self::EXPERIMENT_DRAFTED,
            self::EXPERIMENT_STARTED,
            self::EXPERIMENT_PAUSED,
            self::EXPERIMENT_RESUMED,
            self::EXPERIMENT_COMPLETED,
            self::EXPERIMENT_ARCHIVED,
            self::EXPERIMENT_CANDIDATE_ASSIGNED,
            self::EXPERIMENT_DECISION_RUN_STARTED,
            self::EXPERIMENT_DECISION_RUN_COMPLETED,
            self::EXPERIMENT_DECISION_RUN_FAILED,
            self::EXPERIMENT_DECISION_RECOMMENDATION_CREATED,
            self::EXPERIMENT_DECISION_RECOMMENDATION_ACCEPTED,
            self::EXPERIMENT_DECISION_RECOMMENDATION_DISMISSED,
            self::EXPERIMENT_DECISION_RECOMMENDATION_SUPERSEDED,
            self::OPTIMIZATION_RUN_STARTED,
            self::OPTIMIZATION_RUN_COMPLETED,
            self::OPTIMIZATION_RUN_FAILED,
            self::OPTIMIZATION_RECOMMENDATION_CREATED,
            self::OPTIMIZATION_RECOMMENDATION_ACCEPTED,
            self::OPTIMIZATION_RECOMMENDATION_DISMISSED,
            self::OPTIMIZATION_RECOMMENDATION_MATERIALIZED,
            self::OPTIMIZATION_RECOMMENDATION_STALE,
            self::OPTIMIZATION_RECOMMENDATION_SUPERSEDED,
            self::SIGNAL_DETECTED,
            self::CANDIDATE_DETECTED,
            self::CANDIDATE_RESEARCHED,
            self::CANDIDATE_SCORED,
            self::CANDIDATE_QUALIFIED,
            self::CANDIDATE_MONITORING_STARTED,
            self::CANDIDATE_DISQUALIFIED,
            self::HANDOFF_PREPARED,
        ];
    }
}
