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
            'budget_configured'=>false,'live_verified'=>false,'launch_available'=>false];
        try{
            $profile=$this->profiles->forTenant($org);
            if($profile===null)return $result;
            $result['configuration_hash']=$profile->configurationHash;
            $result['max_cost_usd']=number_format($profile->maxCostUsd,4,'.','');
            $result['daily_run_limit']=$profile->admission->dailyRunLimit;
            $profile->assertAccepted($org);
        }catch(DomainException){
            $result['status']='CONFIGURATION_BLOCKED';return $result;
        }
        $result['budget_configured']=$this->governance->monthlyBudget($org,'USD')!==null;
        $result['status']=$result['budget_configured']?'APPROVAL_BINDING_REQUIRED':'BUDGET_REQUIRED';
        return $result;
    }
}
