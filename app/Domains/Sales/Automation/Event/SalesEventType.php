<?php
declare(strict_types=1);

namespace Domains\Sales\Automation\Event;

/** Canonical event names that integrations, detectors and Sales operations may publish through the Kernel EventBus. */
final class SalesEventType
{
    public const ACTIVITY_RECORDED = 'sales.activity.recorded';
    public const ACTIVITY_COMPLETED = 'sales.activity.completed';
    public const ACTIVITY_CANCELLED = 'sales.activity.cancelled';
    public const ACTIVITY_RESCHEDULED = 'sales.activity.rescheduled';
    public const ACTIVITY_REMINDER_DUE = 'sales.activity.reminder.due';
    public const OFFER_CREATED = 'sales.offer.created';
    public const OFFER_REVISED = 'sales.offer.revised';
    public const OFFER_STATE_CHANGED = 'sales.offer.state_changed';
    public const NEGOTIATION_RECORDED = 'sales.negotiation.recorded';
    public const OFFER_DELIVERY_QUEUED = 'sales.offer.delivery.queued';
    public const LEAD_CONTACTED = 'sales.lead.contacted';
    public const LEAD_QUALIFIED = 'sales.lead.qualified';
    public const LEAD_DISQUALIFIED = 'sales.lead.disqualified';
    public const DEAL_WON = 'sales.deal.won';
    public const DEAL_LOST = 'sales.deal.lost';
    public const DEAL_STUCK = 'sales.deal.stuck';
    public const DEAL_OWNER_ASSIGNED = 'sales.deal.owner_assigned';
    public const MESSAGE_RECEIVED = 'sales.message.received';
    public const MESSAGE_SENT = 'sales.message.sent';
    public const MEETING_COMPLETED = 'sales.meeting.completed';
    public const FOLLOWUP_CREATED = 'sales.followup.created';
    public const FOLLOWUP_COMPLETED = 'sales.followup.completed';
    public const FOLLOWUP_MISSED = 'sales.followup.missed';
    public const TASK_CREATED = 'sales.task.created';
    public const TASK_COMPLETED = 'sales.task.completed';
    public const NO_ACTIVITY_DETECTED = 'sales.no_activity_detected';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::ACTIVITY_RECORDED, self::ACTIVITY_COMPLETED, self::ACTIVITY_CANCELLED, self::ACTIVITY_RESCHEDULED, self::ACTIVITY_REMINDER_DUE, self::OFFER_CREATED, self::OFFER_REVISED, self::OFFER_STATE_CHANGED, self::NEGOTIATION_RECORDED, self::OFFER_DELIVERY_QUEUED,
            self::LEAD_CONTACTED, self::LEAD_QUALIFIED, self::LEAD_DISQUALIFIED,
            self::DEAL_WON, self::DEAL_LOST, self::DEAL_STUCK, self::DEAL_OWNER_ASSIGNED,
            self::MESSAGE_RECEIVED, self::MESSAGE_SENT,
            self::MEETING_COMPLETED, self::FOLLOWUP_CREATED, self::FOLLOWUP_COMPLETED,
            self::FOLLOWUP_MISSED, self::TASK_CREATED, self::TASK_COMPLETED, self::NO_ACTIVITY_DETECTED,
        ];
    }
}
