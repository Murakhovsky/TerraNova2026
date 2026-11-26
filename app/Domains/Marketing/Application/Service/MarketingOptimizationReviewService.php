<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Domains\Marketing\Application\Contract\MarketingOptimizationReceiptStore;
use Domains\Marketing\Domain\MarketingExperimentPolicy;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;

/** Only the canonical approved worker records reviews. A review is not a change to advertising. */
final readonly class MarketingOptimizationReviewService
{
    public function __construct(private MarketingOptimizationRuleService $rules,private MarketingOptimizationReceiptStore $store,
        private ActiveModuleResolver $modules){}

    public function prepare(TenantContext $actor,string $account,array $parameters):array
    {
        $this->guard($actor);
        $keys=array_keys($parameters);sort($keys,SORT_STRING);
        if($keys!==['baseline_end','baseline_run_id','baseline_start','current_run_id','evaluation_hash','external_id','rule','threshold_percent','window_end','window_start'])
            throw new DomainException('Exact review parameters required.');
        $expected=$parameters['evaluation_hash']??null;unset($parameters['evaluation_hash']);
        $evaluation=$this->rules->evaluate($actor,['account_id'=>$account,...$parameters]);
        if(!is_string($expected)||!hash_equals($expected,$evaluation['evaluation_hash'])
            ||$evaluation['status']!=='TRIGGERED_REVIEW_REQUIRED'||!$evaluation['review_recommended'])
            throw new DomainException('Current exact triggered evaluation required.');
        return $evaluation;
    }

    public function record(TenantContext $actor,string $account,array $parameters,string $key,string $actionId):array
    {
        $org=$this->guard($actor);$actorId=$actor->userId()->value();
        if(!preg_match('/^wf:[a-f0-9]{64}$/D',$key)||!preg_match('/^[a-f0-9]{32}$/D',$actionId))
            throw new DomainException('Canonical review Action identity required.');
        $request=self::requestHash($actorId,$account,$parameters,$actionId);
        $existing=$this->store->byKey($org,$key);
        if($existing!==null)return self::verify($existing,$actorId,$account,$parameters,$actionId);
        $ev=$this->prepare($actor,$account,$parameters);
        $receipt=['id'=>'mkor-'.bin2hex(random_bytes(12)),'kind'=>'RULE_REVIEW','actor_id'=>$actorId,'action_id'=>$actionId,
            'recorded_at'=>(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
            'evidence_hash'=>$ev['evaluation_hash'],'account_id'=>$account,'parameters'=>$parameters,'evaluation'=>$ev,
            'review_recorded'=>true,'provider_mutation'=>false,'financial_mutation'=>false,'effect_confirmed'=>false];
        return self::verify($this->store->create($org,$key,$request,$receipt),$actorId,$account,$parameters,$actionId);
    }

    public static function requestHash(string $actor,string $account,array $parameters,string $actionId):string
    {return MarketingExperimentPolicy::digest(['optimization-review-v1',$actor,$account,$parameters,$actionId]);}

    public static function verify(array $row,string $actor,string $account,array $parameters,string $actionId):array
    {
        $r=$row['receipt'];$ev=$r['evaluation'];$copy=$ev;unset($copy['evaluation_hash']);
        if(!hash_equals(self::requestHash($actor,$account,$parameters,$actionId),$row['request_hash'])
            ||!hash_equals(MarketingExperimentPolicy::digest($r),$row['receipt_hash'])
            ||$r['kind']!=='RULE_REVIEW'||$r['actor_id']!==$actor||$r['action_id']!==$actionId||$r['account_id']!==$account
            ||MarketingExperimentPolicy::digest($r['parameters'])!==MarketingExperimentPolicy::digest($parameters)
            ||$r['evidence_hash']!==$parameters['evaluation_hash']||$ev['evaluation_hash']!==$r['evidence_hash']
            ||!hash_equals($ev['evaluation_hash'],MarketingExperimentPolicy::digest($copy))
            ||$ev['account_id']!==$account||$ev['status']!=='TRIGGERED_REVIEW_REQUIRED'||$ev['review_recommended']!==true
            ||$r['review_recorded']!==true||$r['provider_mutation']!==false||$r['financial_mutation']!==false||$r['effect_confirmed']!==false)
            throw new DomainException('Native review receipt differs from approved Action.');
        foreach($parameters as $field=>$value)if(($ev[$field]??null)!==$value)throw new DomainException('Review input evidence mismatch.');
        return ['review_id'=>$r['id'],'receipt_hash'=>$row['receipt_hash'],'evaluation_hash'=>$r['evidence_hash'],
            'account_id'=>$account,'external_id'=>$ev['external_id'],'recorded_at'=>$r['recorded_at'],'review_recorded'=>true,
            'provider_mutation'=>false,'financial_mutation'=>false,'effect_confirmed'=>false];
    }

    private function guard(TenantContext $actor):string
    {
        $org=$actor->organizationId()->value();
        if(!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE)
            ||!$this->modules->isEnabled($org,'marketing'))throw new DomainException('Active Marketing manager required.');
        return $org;
    }
}
