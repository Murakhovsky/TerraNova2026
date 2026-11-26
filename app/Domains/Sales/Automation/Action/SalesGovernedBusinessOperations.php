<?php
declare(strict_types=1);

namespace Domains\Sales\Automation\Action;

use DomainException;
use Kernel\Action\Action;

/** Closed mapping to native commands. Never dispatch arbitrary caller-provided bindings. */
final class SalesGovernedBusinessOperations
{
    private const OPERATIONS = [
        'sales.governed.lead.create' => ['sales.lead.create', 'sales_intent', null],
        'sales.governed.lead.intake' => ['sales.lead.intake', 'sales_intent', null],
        'sales.governed.lead.qualify' => ['sales.lead.qualify', 'lead', 'lead_id'],
        'sales.governed.lead.state.manage' => ['sales.lead.state.manage', 'lead', 'lead_id'],
        'sales.governed.client_case.create' => ['sales.client_case.create', 'lead', 'lead_id'],
        'sales.governed.lead.followup.schedule' => ['sales.lead.followup.schedule', 'lead', 'lead_id'],
        'sales.governed.lead.activity.complete' => ['sales.lead.activity.complete', 'lead', 'lead_id'],
        'sales.governed.lead.activity.reschedule' => ['sales.lead.activity.reschedule', 'lead', 'lead_id'],
        'sales.governed.lead.activity.cancel' => ['sales.lead.activity.cancel', 'lead', 'lead_id'],
        'sales.governed.deal.owner.assign' => ['sales.deal.owner.assign', 'deal', 'deal_id'],
        'sales.governed.deal.commercial.set' => ['sales.deal.commercial.set', 'deal', 'deal_id'],
        'sales.governed.pipeline.stage.manage' => ['sales.pipeline.stage.manage', 'deal', 'deal_id'],
        'sales.governed.deal.outcome.record' => ['sales.deal.outcome.record', 'deal', 'deal_id'],
        'sales.governed.activity.record' => ['sales.activity.record', 'deal', 'deal_id'],
        'sales.governed.activity.complete' => ['sales.activity.complete', 'deal', 'deal_id'],
        'sales.governed.activity.reschedule' => ['sales.activity.reschedule', 'deal', 'deal_id'],
        'sales.governed.activity.cancel' => ['sales.activity.cancel', 'deal', 'deal_id'],
        'sales.governed.followup.schedule' => ['sales.followup.schedule', 'deal', 'deal_id'],
        'sales.governed.property_match.record' => ['sales.property_match.record', 'deal', 'deal_id'],
        'sales.governed.offer.create' => ['sales.offer.create', 'deal', 'deal_id'],
        'sales.governed.offer.revise' => ['sales.offer.revise', 'offer', 'offer_id'],
        'sales.governed.offer.transition' => ['sales.offer.transition', 'offer', 'offer_id'],
        'sales.governed.negotiation.record' => ['sales.negotiation.record', 'offer', 'offer_id'],
        'sales.governed.crm.inbox.process' => ['sales.crm.inbox.process', 'sales_crm_inbox', 'inbox_id'],
        'sales.governed.crm.inbox.recover' => ['sales.crm.inbox.recover', 'sales_crm_inbox', 'inbox_id'],
        'sales.governed.order.source.reconcile' => ['sales.order.source.reconcile', 'sales_order_connection', 'connection_id'],
        'sales.governed.order.source.sync.start' => ['sales.order.source.sync.start', 'sales_order_connection', 'connection_id'],
        'sales.governed.team.manage' => ['sales.team.manage', 'sales_team_intent', null],
    ];

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::OPERATIONS);
    }

    public static function nativeId(string $type): string
    {
        return (self::OPERATIONS[$type] ?? throw new DomainException('Unknown governed Sales operation.'))[0];
    }

    /** Derive the pinned target from the typed native payload, not caller-selected authority. */
    public static function proposalTarget(string $type,array $parameters,string $intent):array
    {
        [, $targetType, $field]=self::OPERATIONS[$type]??throw new DomainException('Unknown governed Sales operation.');
        $value=$field===null?$intent:($parameters[$field]??null);
        if((!is_string($value)&&!is_int($value))||(string)$value==='')
            throw new DomainException('Governed Sales target required.');
        if($field===null&&preg_match('/^[a-f0-9]{32}$/D',(string)$value)!==1)
            throw new DomainException('Invalid Sales intent identity.');
        return ['target_type'=>$targetType,'target_id'=>(string)$value,'parameters'=>$parameters];
    }

    public static function assertTarget(Action $action): void
    {
        [, $type, $field] = self::OPERATIONS[$action->type] ?? throw new DomainException('Unknown governed Sales operation.');
        if ($action->targetType !== $type || !is_string($action->targetId)) {
            throw new DomainException('Governed Sales target mismatch.');
        }
        if ($field === null) {
            if (preg_match('/^[a-f0-9]{32}$/D', $action->targetId) !== 1) {
                throw new DomainException('Governed Sales intent identity missing.');
            }
            return;
        }
        $value = $action->parameters[$field] ?? null;
        if ((!is_int($value) && !is_string($value)) || (string) $value !== $action->targetId) {
            throw new DomainException('Governed Sales entity differs from approved target.');
        }
    }
}
