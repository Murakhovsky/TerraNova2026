<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Service;

use DomainException;
use Domains\Marketing\Application\Contract\MarketingExperimentStore;
use Domains\Marketing\Application\Contract\MarketingExperimentStudyReadPort;
use Domains\Marketing\Application\Contract\MarketingPaidReadRepository;
use Domains\Marketing\Domain\MarketingExperimentPolicy;
use Domains\Marketing\Domain\MarketingPlanningPolicy;
use Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingPaidOperationJournal;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;

final readonly class MarketingExperimentService
{
    public function __construct(private MarketingExperimentStore $store,private MarketingPaidLaunchPlanService $plans,
        private MysqlMarketingPaidOperationJournal $journal,private MarketingBudgetOptimizationService $performance,private ActiveModuleResolver $modules,
        private ?MarketingExperimentStudyReadPort $studies=null,private ?MarketingPaidReadRepository $accounts=null){}

    public function register(TenantContext $actor,string $control,string $candidate,string $factor,string $key):array
    {
        $org=$this->guard($actor);$key=MarketingPlanningPolicy::key($key);
        MarketingPlanningPolicy::id($control,'mkpl-');MarketingPlanningPolicy::id($candidate,'mkpl-');
        MarketingExperimentPolicy::factor($factor);
        $requestHash=MarketingExperimentPolicy::digest([$actor->userId()->value(),$control,$candidate,$factor]);
        $existing=$this->store->byKey($org,$key);
        if($existing!==null){
            if(!hash_equals($existing['request_hash'],$requestHash))throw new DomainException('Experiment idempotency conflict.');
            return $this->project($actor,$existing);
        }
        $protocol=MarketingExperimentPolicy::protocol($this->plans->experimentPins($actor,$control,$factor),
            $this->plans->experimentPins($actor,$candidate,$factor),$factor,gmdate('Y-m-d'));
        $this->assertPins($actor,$protocol);
        foreach([$control,$candidate] as $id)
            foreach(MysqlMarketingPaidOperationJournal::OPERATIONS as $operation)
                if($this->journal->get($org,$id,$operation)!==null)
                    throw new DomainException('Register an experiment before any native paid operation.');
        return $this->project($actor,$this->store->create($org,$actor->userId()->value(),$key,$requestHash,$protocol));
    }

    public function get(TenantContext $actor,string $id):array
    {
        return $this->project($actor,$this->row($actor,$id));
    }

    public function observe(TenantContext $actor,string $id,string $controlSnapshot,string $candidateSnapshot):array
    {
        $row=$this->row($actor,$id);$protocol=$row['protocol'];
        $this->assertPins($actor,$protocol);
        $control=$this->performance->comparisonSource($actor,$protocol['control']['plan_id'],$controlSnapshot);
        $candidate=$this->performance->comparisonSource($actor,$protocol['candidate']['plan_id'],$candidateSnapshot);
        $this->assertPins($actor,$protocol);
        return ['experiment_id'=>$id,'protocol_hash'=>$row['protocol_hash'],...MarketingExperimentPolicy::observe($protocol,$control,$candidate)];
    }

    public function observeStudy(TenantContext $actor,string $id,string $studyId,string $controlSnapshot,string $candidateSnapshot):array
    {
        $row=$this->row($actor,$id);$protocol=$row['protocol'];
        $org=$this->guard($actor);
        if($this->studies===null||$this->accounts===null)throw new DomainException('Provider study read unavailable.');
        $before=$this->observe($actor,$id,$controlSnapshot,$candidateSnapshot);
        $a=$this->performance->comparisonSource($actor,$protocol['control']['plan_id'],$controlSnapshot);
        $b=$this->performance->comparisonSource($actor,$protocol['candidate']['plan_id'],$candidateSnapshot);
        $account=$this->accounts->account($org,$a['account_id']);
        if($account===null||$account['provider']!=='meta'||$account['connection_status']!=='READ_ONLY'
            ||!in_array('ads_read',$account['capability_scope'],true)||$account['revision']!==$a['account_revision']
            ||$account['timezone']!=='UTC'||$account['currency']!==$a['currency'])
            throw new DomainException('Registered Meta account revision unavailable.');
        $study=$this->studies->inspectSplitStudy($org,$account['ad_account_id'],$account['credential_ref'],$studyId,
            $a['provider_campaign_id'],$b['provider_campaign_id'],$a['window_start'],$a['window_end']);
        $after=$this->observe($actor,$id,$controlSnapshot,$candidateSnapshot);
        $freshA=$this->performance->comparisonSource($actor,$protocol['control']['plan_id'],$controlSnapshot);
        $freshB=$this->performance->comparisonSource($actor,$protocol['candidate']['plan_id'],$candidateSnapshot);
        $freshAccount=$this->accounts->account($org,$a['account_id']);
        if(MarketingExperimentPolicy::digest([$before,$a,$b])!==MarketingExperimentPolicy::digest([$after,$freshA,$freshB])
            ||$freshAccount===null||$freshAccount['revision']!==$account['revision'])
            throw new DomainException('Experiment sources changed during provider read.');
        return [...$after,'study'=>$study,'study_evidence_hash'=>MarketingExperimentPolicy::digest([$after,$study,$a,$b])];
    }

    private function row(TenantContext $actor,string $id):array
    {
        return $this->store->get($this->guard($actor),MarketingPlanningPolicy::id($id,'mkex-'))
            ??throw new DomainException('Experiment protocol unavailable.');
    }

    private function assertPins(TenantContext $actor,array $protocol):void
    {
        foreach(['control','candidate'] as $arm){
            $pin=$protocol[$arm];$fresh=$this->plans->experimentPins($actor,$pin['plan_id'],$protocol['factor']);
            if(!hash_equals(MarketingExperimentPolicy::digest($pin),MarketingExperimentPolicy::digest($fresh)))
                throw new DomainException('Experiment planning pins changed.');
        }
    }

    private function project(TenantContext $actor,array $row):array
    {
        $current=true;
        try{$this->assertPins($actor,$row['protocol']);}catch(DomainException){$current=false;}
        unset($row['request_hash']);
        return [...$row,'state'=>'REGISTERED_PROTOCOL_ONLY','source_pins_current'=>$current,'randomization_required'=>true,
            'randomization_verified'=>false,'experiment_execution_authorized'=>false,'provider_mutation'=>false,'financial_mutation'=>false,
            'paid_activation_approved'=>false,'approval_required_for_execution'=>true];
    }

    private function guard(TenantContext $actor):string
    {
        $org=$actor->organizationId()->value();
        if(!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE)
            ||!$this->modules->isEnabled($org,'marketing'))throw new DomainException('Marketing manager and active module required.');
        return $org;
    }
}
