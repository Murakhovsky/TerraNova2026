<?php
declare(strict_types=1);
namespace App\Infrastructure\Marketing;

use Domains\Marketing\Infrastructure\Hermes\HermesAnalystConfigurationRegistry;
use Domains\Marketing\Infrastructure\Hermes\HermesAnalystProfileProbe;
use Kernel\Llm\LlmGovernanceRepositoryInterface;
use Kernel\Module\ActiveModuleResolver;
use Throwable;

/** Operator diagnostic only; never creates a user identity, approval, Action or runtime turn. */
final readonly class HermesAnalystOperatorPreflight
{
    public function __construct(private HermesAnalystConfigurationRegistry $profiles,private HermesAnalystProfileProbe $probe,
        private LlmGovernanceRepositoryInterface $governance,private ActiveModuleResolver $modules){}

    /** @return array<string,mixed> */
    public function inspect(string $org,bool $remote=false):array
    {
        $report=['organization_id'=>$org,'status'=>'BLOCKED','stage'=>'TENANT_MODULE','configuration_hash'=>null,
            'remote_probe_requested'=>$remote,'remote_probe_passed'=>false,'toolsets_reported'=>null,
            'monthly_budget_usd'=>null,'monthly_consumed_usd'=>null,'per_run_ceiling_usd'=>null,
            'read_only'=>true,'generation_requested'=>false,'provider_cost_cap_verified'=>false,
            'isolation_verified'=>false,'release_authorized'=>false];
        try{
            if(!preg_match('/^[A-Za-z0-9_.:-]{1,40}$/D',$org)||!$this->modules->isEnabled($org,'marketing'))return $report;
            $report['stage']='CONFIGURATION';
            $profile=$this->profiles->forTenant($org);
            if($profile===null)return $report;
            $profile->assertAccepted($org);
            $report['configuration_hash']=$profile->configurationHash;
            $report['per_run_ceiling_usd']=$profile->maxCostUsd;
            $report['stage']='CANONICAL_ROLLOUT';
            $rollout=getenv('COS_FEDERATION_CANONICAL_ORGANIZATION');
            if(!is_string($rollout)||$rollout===''||!hash_equals($org,$rollout))return $report;
            $report['stage']='LLM_BUDGET';
            $limit=$this->governance->monthlyBudget($org,'USD');
            $report['monthly_budget_usd']=$limit;
            if($limit===null)return $report;
            $spent=$this->governance->monthlySpend($org,'USD');
            $report['monthly_consumed_usd']=$spent;
            // Diagnostic only: actual available budget includes outstanding reservations.
            if($limit-$spent+0.0000001<$profile->maxCostUsd)return $report;
            $report['stage']='CREDENTIAL';
            $token=$this->probe->token($profile->connection);
            if(!$remote){
                $report['status']='LOCAL_PREFLIGHT_PASSED';$report['stage']='REMOTE_NOT_REQUESTED';return $report;
            }
            $report['stage']='REMOTE_TOOLSET_DISCOVERY';
            $facts=$this->probe->inspect($profile->connection,$token);
            $report['toolsets_reported']=$facts['toolsets_reported'];
            $report['remote_probe_passed']=true;
            $report['status']='REMOTE_PREFLIGHT_PASSED_NOT_ACCEPTED';$report['stage']='INDEPENDENT_ACCEPTANCE_REQUIRED';
            return $report;
        }catch(Throwable){
            // No endpoint URL, secret, raw response or exception text in operator output.
            return $report;
        }
    }
}
