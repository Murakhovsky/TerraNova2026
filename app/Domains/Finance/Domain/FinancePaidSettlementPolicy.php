<?php
declare(strict_types=1);
namespace Domains\Finance\Domain;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;

/**
 * Pure settlement admission calculation. Inputs must originate in Finance-owned
 * claim storage, Marketing's native terminal journal and an independently reviewed
 * provider-document source, never in a caller/AI payload. Matching is not execution.
 */
final class FinancePaidSettlementPolicy
{
    public function prepare(string $organizationId,array $claim,array $plan,array $terminal,array $observation,array $document):array
    {
        if($organizationId===''||strlen($organizationId)>190)throw new DomainException('Trusted tenant required.');
        foreach([$claim,$plan,$terminal,$observation,$document] as $record){
            if(($record['organization_id']??null)!==$organizationId)throw new DomainException('Foreign or unscoped settlement evidence.');
        }
        foreach(['source_ref','plan_hash','currency','reservation_id','envelope_id','action_id'] as $key){
            if(!is_string($claim[$key]??null))throw new DomainException('Incomplete native Finance exposure.');
        }
        if(!preg_match('/^mkpl-[a-f0-9]{24}$/D',$claim['source_ref'])
            ||!preg_match('/^[a-f0-9]{64}$/D',$claim['plan_hash'])
            ||!preg_match('/^[a-f0-9]{32}$/D',$claim['action_id']))throw new DomainException('Invalid native exposure identity.');
        BudgetControlPolicy::id($claim['envelope_id']);
        BudgetControlPolicy::id($claim['reservation_id'],'finr-');
        $cap=BudgetControlPolicy::amount($claim['amount_minor']??null);
        if(!in_array($claim['currency'],['USD','EUR','UAH','PLN'],true)
            ||($plan['id']??null)!==$claim['source_ref']||($plan['plan_hash']??null)!==$claim['plan_hash']
            ||($plan['currency']??null)!==$claim['currency']||($plan['lifetime_minor']??null)!==$cap
            ||($plan['account_timezone']??null)!=='UTC'
            ||!is_string($plan['account_id']??null)||!preg_match('/^mads-[a-f0-9]{24}$/D',$plan['account_id'])){
            throw new DomainException('Finance claim and immutable Marketing plan differ.');
        }
        $start=self::date($plan['period_start']??null);$end=self::date($plan['period_end']??null);
        if($start>$end||$start->diff($end)->days>30)throw new DomainException('Invalid paid settlement window.');
        $campaign=$terminal['provider_campaign_id']??null;
        if(($terminal['plan_id']??null)!==$plan['id']||($terminal['activation_action_id']??null)!==$claim['action_id']
            ||($terminal['state']??null)!=='PAUSED_CONFIRMED'||!is_string($campaign)||!preg_match('/^[0-9]{1,30}$/D',$campaign)
            ||!is_string($terminal['pause_action_id']??null)||!preg_match('/^[a-f0-9]{32}$/D',$terminal['pause_action_id'])
            ||($terminal['unresolved_operations']??null)!==[]){
            throw new DomainException('Native terminal campaign and activation receipt required.');
        }
        // A terminal journal is not financial proof. The full approved period must
        // have elapsed; no early release while adjustments may still arrive.
        $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
        if($now<$end->modify('+1 day'))throw new DomainException('Delivery window is not closed.');
        $scope=['ad_account_ref'=>$plan['account_id'],'external_id'=>$campaign,'grain'=>'campaign',
            'window_start'=>$start->format('Y-m-d'),'window_end'=>$end->format('Y-m-d'),
            'currency'=>$claim['currency'],'timezone'=>'UTC'];
        foreach($scope as $field=>$value){
            if(($observation[$field]??null)!==$value||($document[$field]??null)!==$value)
                throw new DomainException('Document, source observation and native campaign scopes differ.');
        }
        if(!in_array($observation['source_status']??null,['PROVISIONAL','REVISED','FINALIZED_BY_SOURCE'],true)
            ||!is_string($observation['source_snapshot_ref']??null)||!preg_match('/^mis-[a-f0-9]{24}$/D',$observation['source_snapshot_ref'])
            ||!is_string($observation['source_hash']??null)||!preg_match('/^[a-f0-9]{64}$/D',$observation['source_hash'])
            ||!is_int($observation['source_revision']??null)||$observation['source_revision']<1
            ||!is_string($observation['attribution_hash']??null)||!preg_match('/^[a-f0-9]{64}$/D',$observation['attribution_hash'])){
            throw new DomainException('Missing native Marketing observation provenance.');
        }
        // Independent source-document review must pin the exact observed revision.
        foreach(['source_snapshot_ref','source_hash','source_revision','attribution_hash'] as $field){
            if(($document[$field]??null)!==$observation[$field])throw new DomainException('Document review is stale against current source.');
        }
        if(($document['provider']??null)!=='meta'||($document['document_state']??null)!=='INDEPENDENTLY_REVIEWED_FINAL'
            ||($document['allocation_scope']??null)!=='EXACT_CAMPAIGN_NET_MEDIA_SPEND'
            ||($document['plan_hash']??null)!==$plan['plan_hash']
            ||($document['pause_action_id']??null)!==$terminal['pause_action_id']
            ||!is_string($document['document_sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$document['document_sha256'])){
            throw new DomainException('Independent final provider-document review required.');
        }
        foreach(['document_ref','provider_document_id','review_ref'] as $field){
            if(!is_string($document[$field]??null)||!preg_match('/^[A-Za-z0-9_.:-]{3,190}$/D',$document[$field]))
                throw new DomainException('Missing reviewed document identity.');
        }
        if(!is_string($document['reviewer_id']??null)||!preg_match('/^[1-9][0-9]{0,18}$/D',$document['reviewer_id']))
            throw new DomainException('Valid reviewer identity required.');
        if(($document['reviewer_id']??null)===($claim['actor_id']??null))
            throw new DomainException('Exposure claimant cannot independently review final document.');
        $amount=$document['settlement_minor']??null;
        if(!is_int($amount)||$amount<0||$amount>1_000_000_000_000)throw new DomainException('Exact nonnegative minor units required.');
        $reported=self::minorUnits($observation['reported_spend_decimal']??null);
        if($reported!==$amount)throw new DomainException('Document allocation differs from reported media spend; manual variance review required.');
        if($amount>$cap)throw new DomainException('Confirmed spend exceeds held exposure; manual variance review required.');
        $receipt=[
            'organization_id'=>$organizationId,'reservation_id'=>$claim['reservation_id'],'envelope_id'=>$claim['envelope_id'],
            'plan_id'=>$plan['id'],'plan_hash'=>$plan['plan_hash'],'activation_action_id'=>$claim['action_id'],
            'pause_action_id'=>$terminal['pause_action_id'],...$scope,
            'source_snapshot_ref'=>$observation['source_snapshot_ref'],'source_revision'=>$observation['source_revision'],
            'source_hash'=>$observation['source_hash'],'attribution_hash'=>$observation['attribution_hash'],
            'document_ref'=>$document['document_ref'],'provider_document_id'=>$document['provider_document_id'],
            'document_sha256'=>$document['document_sha256'],'review_ref'=>$document['review_ref'],'reviewer_id'=>$document['reviewer_id'],
            'reserved_minor'=>$cap,'proposed_commit_minor'=>$amount,'proposed_release_minor'=>$cap-$amount,
        ];
        return [...$receipt,'candidate_hash'=>hash('sha256',json_encode($receipt,JSON_THROW_ON_ERROR)),
            'state'=>'MATCHED_NOT_SETTLED','finance_settled'=>false,'budget_mutated'=>false,'expense_confirmed'=>false,
            'payment_confirmed'=>false];
    }

    private static function date(mixed $raw):DateTimeImmutable
    {
        if(!is_string($raw))throw new DomainException('Exact UTC period required.');
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$raw,new DateTimeZone('UTC'));
        if(!$date||$date->format('Y-m-d')!==$raw)throw new DomainException('Invalid UTC period.');
        return $date;
    }

    /** P0: no inferred rounding, fees, taxes, exchange conversion or float arithmetic. */
    private static function minorUnits(mixed $raw):int
    {
        if(!is_string($raw)||!preg_match('/^(0|[1-9][0-9]{0,10})(?:\.([0-9]{1,6}))?$/D',$raw,$m))
            throw new DomainException('Invalid exact source amount.');
        $fraction=str_pad($m[2]??'',6,'0');
        if(substr($fraction,2)!=='0000')throw new DomainException('Sub-minor-unit variance needs independent review.');
        return (int)$m[1]*100+(int)substr($fraction,0,2);
    }
}
