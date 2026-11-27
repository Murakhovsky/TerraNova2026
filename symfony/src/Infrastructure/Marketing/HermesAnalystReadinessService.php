<?php
declare(strict_types=1);
namespace App\Infrastructure\Marketing;

use DomainException;
use Domains\Marketing\Infrastructure\Hermes\HermesAnalystConfigurationRegistry;
use Kernel\Llm\LlmGovernanceRepositoryInterface;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;

/** No remote call or secret resolution. Configuration readiness is not live acceptance. */
final readonly class HermesAnalystReadinessService
{
    public function __construct(private HermesAnalystConfigurationRegistry $profiles,private LlmGovernanceRepositoryInterface $governance,
        private ActiveModuleResolver $modules){}

    /** @return array<string,mixed> */
    public function status(TenantContext $actor):array
    {
        $org=$actor->organizationId()->value();
        if(!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE)
            ||!$this->modules->isEnabled($org,'marketing'))throw new DomainException('Marketing manager and active module required.');
        $result=['status'=>'NOT_CONFIGURED','configuration_hash'=>null,'max_cost_usd'=>null,'daily_run_limit'=>null,
            'budget_configured'=>false,'token_limits'=>null,'live_verified'=>false,'launch_available'=>false,'plan_available'=>false];
        try{
            $profile=$this->profiles->forTenant($org);
            if($profile===null)return $result;
            $result['configuration_hash']=$profile->configurationHash;
            $result['max_cost_usd']=number_format($profile->maxCostUsd,4,'.','');
            $result['daily_run_limit']=$profile->admission->dailyRunLimit;
            $profile->assertAccepted($org);
            $result['token_limits']=$profile->tokenBudget?->limits();
        }catch(DomainException){
            $result['status']='CONFIGURATION_BLOCKED';return $result;
        }
        $result['budget_configured']=$this->governance->monthlyBudget($org,'USD')!==null;
        if(!$result['budget_configured']){$result['status']='BUDGET_REQUIRED';return $result;}
        $rollout=getenv('COS_FEDERATION_CANONICAL_ORGANIZATION');
        $result['plan_available']=is_string($rollout)&&$rollout!==''&&hash_equals($org,$rollout);
        $result['status']=$result['plan_available']?'APPROVAL_REQUIRED':'CANONICAL_ROLLOUT_REQUIRED';
        return $result;
    }
}
