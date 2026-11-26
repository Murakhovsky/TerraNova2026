<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Domains\Marketing\Application\Contract\MarketingOptimizationReceiptStore;
use Domains\Marketing\Application\Contract\MarketingOptimizationReviewEvidenceReadPort;
use Domains\Marketing\Domain\MarketingExperimentPolicy;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;

/** Follow-up source observation only. Never attributes lift to a review or changes a rule. */
final readonly class MarketingOptimizationLearningService
{
    public function __construct(private MarketingOptimizationReviewEvidenceReadPort $history,private MarketingOptimizationRuleService $rules,
        private MarketingOptimizationReceiptStore $store,private ActiveModuleResolver $modules){}

    public function preview(TenantContext $actor,string $account,array $p):array
    {
        $this->guard($actor);
        $keys=array_keys($p);sort($keys,SORT_STRING);
        if($keys!==['followup_run_id','followup_window_end','followup_window_start','review_evaluation_id','review_id','review_run_id'])
            throw new DomainException('Exact learning parameters required.');
        $source=$this->history->review($actor,$p['review_run_id'],$p['review_evaluation_id'],$p['review_id']);
        $r=$source['review'];$old=$r['evaluation'];
        if($r['account_id']!==$account||$p['followup_window_start']<=$old['window_end'])
            throw new DomainException('Follow-up requires the same account and a later non-overlapping window.');
        $follow=$this->rules->evaluate($actor,[
            'account_id'=>$account,'external_id'=>$old['external_id'],'rule'=>$old['rule'],'threshold_percent'=>$old['threshold_percent'],
            'current_run_id'=>$p['followup_run_id'],'baseline_run_id'=>$old['current_run_id'],
            'window_start'=>$p['followup_window_start'],'window_end'=>$p['followup_window_end'],
            'baseline_start'=>$old['window_start'],'baseline_end'=>$old['window_end']]);
        foreach(['account_revision','attribution_hash','currency','metric'] as $key)
            if($old[$key]!==$follow[$key])throw new DomainException('Learning source scope drift.');
        if(MarketingExperimentPolicy::digest($follow['baseline_source']??[])!==MarketingExperimentPolicy::digest($old['current_source']??[])
            ||($follow['baseline_value']!==null&&(float)$follow['baseline_value']!==(float)$old['current_value']))
            throw new DomainException('Follow-up baseline differs from approved review evidence.');
        $change=$follow['change_percent'];
        $status=$change===null?'INSUFFICIENT_EVIDENCE':($change==0?'UNCHANGED_OBSERVED':
            (($old['rule']==='CTR_DECLINE'?$change>0:$change<0)?'IMPROVED_OBSERVED':'DEGRADED_OBSERVED'));
        $result=['model'=>'optimization-learning-v1','review_id'=>$r['id'],'review_receipt_hash'=>$source['review_receipt_hash'],
            'review_run_id'=>$source['review_run_id'],'review_evaluation_id'=>$source['review_evaluation_id'],
            'review_recorded_at'=>$r['recorded_at'],'account_id'=>$account,'external_id'=>$old['external_id'],
            'window_relation'=>$p['followup_window_start']>substr($r['recorded_at'],0,10)?'AFTER_REVIEW':'RETROSPECTIVE_ONLY',
            'status'=>$status,'followup_evaluation'=>$follow,
            'next_step'=>match($status){'DEGRADED_OBSERVED'=>'REVIEW_AGAIN','INSUFFICIENT_EVIDENCE'=>'COLLECT_EVIDENCE',default=>'KEEP_OBSERVING'},
            'effect_confirmed'=>false,'causal_attribution'=>false,'rule_change_authorized'=>false,'provider_mutation'=>false,'financial_mutation'=>false];
        return [...$result,'learning_hash'=>MarketingExperimentPolicy::digest($result)];
    }

    public function prepare(TenantContext $actor,string $account,array $p):array
    {
        $expected=$p['learning_hash']??null;unset($p['learning_hash']);
        $keys=array_keys($p);sort($keys,SORT_STRING);
        if($keys!==['followup_run_id','followup_window_end','followup_window_start','review_evaluation_id','review_id','review_run_id'])
            throw new DomainException('Exact learning parameters required.');
        $r=$this->preview($actor,$account,$p);
        if(!is_string($expected)||!hash_equals($expected,$r['learning_hash'])||$r['status']==='INSUFFICIENT_EVIDENCE')
            throw new DomainException('Exact observed follow-up evidence required.');
        return $r;
    }

    public function record(TenantContext $actor,string $account,array $parameters,string $key,string $actionId):array
    {
        $org=$this->guard($actor);$actorId=$actor->userId()->value();
        if(!preg_match('/^wf:[a-f0-9]{64}$/D',$key)||!preg_match('/^[a-f0-9]{32}$/D',$actionId))
            throw new DomainException('Canonical learning Action identity required.');
        $request=self::requestHash($actorId,$account,$parameters,$actionId);
        $existing=$this->store->byKey($org,$key);
        if($existing!==null)return self::verify($existing,$actorId,$account,$parameters,$actionId);
        $learning=$this->prepare($actor,$account,$parameters);
        $receipt=['id'=>'mkol-'.bin2hex(random_bytes(12)),'kind'=>'LEARNING','actor_id'=>$actorId,'action_id'=>$actionId,
            'recorded_at'=>(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
            'evidence_hash'=>self::observationHash($learning),'account_id'=>$account,'parameters'=>$parameters,'learning'=>$learning,
            'learning_recorded'=>true,'provider_mutation'=>false,'financial_mutation'=>false,'effect_confirmed'=>false,'rule_change_authorized'=>false];
        return self::verify($this->store->create($org,$key,$request,$receipt),$actorId,$account,$parameters,$actionId);
    }
    public function get(TenantContext $actor,string $id):array
    {
        $org=$this->guard($actor);
        if(!preg_match('/^mkol-[a-f0-9]{24}$/D',$id))throw new DomainException('Invalid learning receipt id.');
        $row=$this->store->get($org,$id)??throw new DomainException('Learning receipt unavailable.');
        $r=$row['receipt'];
        return self::verify($row,$r['actor_id'],$r['account_id'],$r['parameters'],$r['action_id']);
    }
    private static function observationHash(array $learning):string
    {return MarketingExperimentPolicy::digest(['learning-observation-v1',$learning['review_receipt_hash'],$learning['followup_evaluation']['evaluation_hash']]);}
    public static function requestHash(string $actor,string $account,array $parameters,string $actionId):string
    {return MarketingExperimentPolicy::digest(['optimization-learning-record-v1',$actor,$account,$parameters,$actionId]);}

    public static function verify(array $row,string $actor,string $account,array $parameters,string $actionId):array
    {
        $r=$row['receipt'];$l=$r['learning'];$copy=$l;unset($copy['learning_hash']);
        if(!hash_equals(self::requestHash($actor,$account,$parameters,$actionId),$row['request_hash'])
            ||!hash_equals(MarketingExperimentPolicy::digest($r),$row['receipt_hash'])
            ||$r['kind']!=='LEARNING'||$r['actor_id']!==$actor||$r['action_id']!==$actionId||$r['account_id']!==$account
            ||MarketingExperimentPolicy::digest($r['parameters'])!==MarketingExperimentPolicy::digest($parameters)
            ||$r['evidence_hash']!==self::observationHash($l)||$l['learning_hash']!==$parameters['learning_hash']
            ||!hash_equals($l['learning_hash'],MarketingExperimentPolicy::digest($copy))||$l['account_id']!==$account
            ||!in_array($l['status'],['IMPROVED_OBSERVED','DEGRADED_OBSERVED','UNCHANGED_OBSERVED'],true)
            ||$r['learning_recorded']!==true||$r['provider_mutation']!==false||$r['financial_mutation']!==false
            ||$r['effect_confirmed']!==false||$r['rule_change_authorized']!==false)
            throw new DomainException('Native learning receipt differs from approved Action.');
        foreach(['review_id','review_run_id','review_evaluation_id','learning_hash'] as $field)
            if(($l[$field]??null)!==$parameters[$field])throw new DomainException('Learning review reference mismatch.');
        foreach(['followup_run_id'=>'current_run_id','followup_window_start'=>'window_start','followup_window_end'=>'window_end'] as $field=>$metric)
            if(($l['followup_evaluation'][$metric]??null)!==$parameters[$field])throw new DomainException('Learning follow-up input mismatch.');
        return ['learning_id'=>$r['id'],'receipt_hash'=>$row['receipt_hash'],'recorded_at'=>$r['recorded_at'],
            'learning'=>$l,'learning_recorded'=>true,'effect_confirmed'=>false,'rule_change_authorized'=>false,'provider_mutation'=>false,'financial_mutation'=>false];
    }
    private function guard(TenantContext $actor):string
    {
        $org=$actor->organizationId()->value();
        if(!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE)
            ||!$this->modules->isEnabled($org,'marketing')||!$this->modules->isEnabled($org,'federation'))throw new DomainException('Active Marketing manager required.');
        return $org;
    }
}
