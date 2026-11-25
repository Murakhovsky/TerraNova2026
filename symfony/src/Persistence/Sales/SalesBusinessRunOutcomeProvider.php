<?php
declare(strict_types=1);

namespace App\Persistence\Sales;

use App\Persistence\Federation\FederationOutcomeSourceNotReady;
use App\Persistence\Workflow\DoctrineWorkflowActionReceiptVerifier;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use DomainException;
use Domains\Sales\Application\Contract\SalesMutationReceiptRepositoryInterface;
use Domains\Sales\Application\Contract\SalesActivityEvidenceReadPort;
use Domains\Sales\Application\Service\SalesActivityOutcomeEvidence;
use Domains\Sales\Application\Contract\SalesWorkspaceReadModelInterface;
use Domains\Sales\Application\Support\DealStateSnapshot;
use Domains\Sales\Automation\Action\SalesGovernedBusinessOperations;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Platform\Orchestration\Goal\RunScopedGoalOutcomeEvidenceProviderInterface;
use Platform\Workflow\Model\WorkflowRunIdentity;

/** Independently attest receipt-bound native state, not a successful Action flag.
 *  Current-state proof is intentionally conservative when subsequent steps change that state.
 */
final readonly class SalesBusinessRunOutcomeProvider implements RunScopedGoalOutcomeEvidenceProviderInterface
{
    public const CRITERION = 'sales.business.native_operation_verified';
    private const DEAL_OPERATIONS = [
        'sales.governed.deal.owner.assign'=>'owner.assign',
        'sales.governed.deal.commercial.set'=>'commercial.set',
        'sales.governed.pipeline.stage.manage'=>'stage.manage',
        'sales.governed.deal.outcome.record'=>'outcome.record',
    ];

    private const SCHEDULED = [
        'sales.governed.lead.followup.schedule'=>'lead.schedule',
        'sales.governed.lead.activity.complete'=>'lead.complete',
        'sales.governed.lead.activity.reschedule'=>'lead.reschedule',
        'sales.governed.lead.activity.cancel'=>'lead.cancel',
        'sales.governed.activity.cancel'=>'deal.cancel',
    ];
    private const OFFERS = [
        'sales.governed.offer.create'=>'create',
        'sales.governed.offer.revise'=>'revise',
        'sales.governed.offer.transition'=>'transition',
        'sales.governed.negotiation.record'=>'negotiation',
    ];

    public function __construct(
        private Connection $db,
        private SalesWorkspaceReadModelInterface $sales,
        private SalesMutationReceiptRepositoryInterface $nativeReceipts,
        private DoctrineWorkflowActionReceiptVerifier $receipts,
        private SalesActivityEvidenceReadPort $activityEvidence,
        private \Domains\Sales\Application\Contract\SalesScheduledActivityRepositoryInterface $scheduled,
        private \Domains\Sales\Application\Contract\SalesOfferRepositoryInterface $offers,
        private \Domains\Sales\Application\Contract\SalesTeamAdministrationInterface $teams,
    ) {}

    public function domain(): string { return 'sales'; }
    public function supports(string $criterionId): bool { return $criterionId===self::CRITERION; }
    public function observe(string $organizationId,string $criterionId,DateTimeImmutable $from,DateTimeImmutable $to): array
    { throw new FederationOutcomeSourceNotReady('Native Sales business outcome requires canonical Run.'); }

    public function observeRun(TenantContext $actor,string $runId,string $criterionId,DateTimeImmutable $from,DateTimeImmutable $to): array
    {
        if (!$actor->isManager() || !$actor->allows(TenantPermissions::MANAGE)
            || !$this->supports($criterionId) || $to<=$from) throw new DomainException('Invalid Sales business outcome scope.');
        $org=$actor->organizationId()->value();
        $rows=$this->db->fetchAllAssociative('SELECT w.state,w.input_digest,s.step_id,s.state AS step_state,s.result_reference,s.capability_id,
            a.id AS action_id,a.type AS action_type,a.parameters,a.target_id,a.source_id,a.idempotency_key,a.correlation_id
            FROM cos_workflow_runs w JOIN cos_workflow_steps s ON s.organization_id=w.organization_id AND s.run_id=w.run_id
            LEFT JOIN cos_actions a ON a.organization_id=s.organization_id AND s.result_reference=CONCAT("action:",a.id)
            WHERE w.organization_id=? AND BINARY w.organization_id=BINARY ? AND w.run_id=? AND w.definition_id="federation.approved-plan"',
            [$org,$org,$runId]);
        $evidence=[];
        foreach ($rows as $r) {
            if (!in_array($r['capability_id'],SalesGovernedBusinessOperations::types(),true)) continue;
            if (!isset(self::DEAL_OPERATIONS[$r['capability_id']]) && $r['capability_id']!=='sales.governed.lead.state.manage'
                && !SalesActivityOutcomeEvidence::supports($r['capability_id'])
                && !isset(self::SCHEDULED[$r['capability_id']]) && !isset(self::OFFERS[$r['capability_id']])
                && $r['capability_id']!=='sales.governed.team.manage')
                throw new FederationOutcomeSourceNotReady('Independent native proof not yet available for every Sales operation in this Run.');
            if ($r['state']!=='completed'||$r['step_state']!=='completed'||!is_string($r['action_id']??null))
                throw new FederationOutcomeSourceNotReady('Completed Sales business Run required.');
            $proof=$this->receipts->attestForAudit(new WorkflowRunIdentity($org,$runId),$r['step_id'],$r['result_reference'],$r['input_digest']);
            if (!$proof->verified || $r['action_type']!==$r['capability_id']) throw new DomainException('Untrusted Sales Action receipt.');
            $reference=$this->verify($org,$r);
            if (in_array($reference,$evidence,true)) throw new DomainException('Duplicate Sales business proof.');
            $evidence[]=$reference;
        }
        if ($evidence===[]) throw new FederationOutcomeSourceNotReady('No supported Sales business operations in this Run.');
        return ['value'=>count($evidence),'evidence'=>$evidence,'source'=>'sales.native_business_operation.v1',
            'window_start'=>$from->format('Y-m-d\TH:i:s.uP'),'window_end'=>$to->format('Y-m-d\TH:i:s.uP'),
            'attribution'=>'run_linked_action','run_id'=>$runId];
    }

    private function verify(string $org,array $r): string
    {
        $p=json_decode($r['parameters'],true,32,JSON_THROW_ON_ERROR);
        $type=$r['capability_id'];
        $user=(int)$r['source_id'];
        $key=$r['idempotency_key'];
        if (!is_array($p)||$user<1||!is_string($key)||!preg_match('/^wf:[a-f0-9]{64}$/D',$key))
            throw new DomainException('Invalid Sales receipt identity.');
        if ($type==='sales.governed.lead.state.manage') {
            $id=$p['lead_id'];
            $hash=hash('sha256',json_encode([$user,$id,$p['expected_status'],$p['target_status']],JSON_THROW_ON_ERROR));
            $receipt=$this->nativeReceipts->find($org,'lead.state.manage',$key);
            if (!is_string($receipt)||!hash_equals('done:'.$hash,$receipt)||(string)$id!==$r['target_id'])
                throw new DomainException('Native lead receipt differs from approved operation.');
            $row=$this->sales->lead($org,$id);
            if ($row===null||($row['organization_id']??null)!==$org||!in_array($row['id']??null,[$id,(string)$id],true)
                ||($row['status']??null)!==$p['target_status'])
                throw new FederationOutcomeSourceNotReady('Approved lead state is no longer observable.');
            return 'sales:lead:'.$id.':'.$key;
        }
        if (SalesActivityOutcomeEvidence::supports($type)) {
            return (new SalesActivityOutcomeEvidence($this->activityEvidence,$this->nativeReceipts))
                ->verify($org,$type,$user,(string)$r['target_id'],$key,$p);
        }
        if (isset(self::SCHEDULED[$type])) return $this->scheduledProof($org,$r,$p,$user,$key);
        if (isset(self::OFFERS[$type])) return $this->offerProof($org,$r,$p,$user,$key);
        if ($type==='sales.governed.team.manage') return $this->teamProof($org,$p,$user,$key);
        $op=self::DEAL_OPERATIONS[$type];
        $id=$p['deal_id'];
        ksort($p);
        $hash=hash('sha256',json_encode([$user,$op,$p],JSON_THROW_ON_ERROR));
        $receipt=$this->nativeReceipts->find($org,'deal.'.$op,$key);
        if (!is_string($receipt)||preg_match('/^done:([a-f0-9]{64}):[01]$/D',$receipt,$m)!==1
            ||!hash_equals($hash,$m[1])||(string)$id!==$r['target_id'])
            throw new DomainException('Native deal receipt differs from approved operation.');
        $row=$this->sales->deal($org,$id);
        if ($row===null) throw new FederationOutcomeSourceNotReady('Native deal is no longer observable.');
        $state=DealStateSnapshot::fromNative($row,$org,$id);
        $matches=match($op) {
            'owner.assign'=>$state['owner_id']===$p['owner_id'],
            'commercial.set'=>$state['deal_value']===$p['deal_value']&&$state['currency']===$p['currency'],
            'stage.manage'=>$state['stage_id']===$p['target_stage_id']&&!in_array($state['status'],['closed','lost'],true),
            'outcome.record'=>$state['stage_id']===$p['target_stage_id']
                &&$state['status']===($p['outcome']==='won'?'closed':'lost')
                &&($p['outcome']==='won'||$state['lost_reason_id']===$p['lost_reason_id']),
            default=>false,
        };
        if (!$matches) throw new FederationOutcomeSourceNotReady('Approved deal state is no longer observable.');
        return 'sales:deal:'.$id.':'.$key;
    }

    private function scheduledProof(string $org,array $r,array $p,int $user,string $key): string
    {
        $op=self::SCHEDULED[$r['capability_id']];
        $kind=$op==='deal.cancel'?'deal':'lead';
        $target=$p[$kind.'_id'];
        ksort($p);
        $hash=hash('sha256',json_encode([$user,$op,$p],JSON_THROW_ON_ERROR));
        $receipt=$this->nativeReceipts->find($org,'operator.'.$op,$key);
        if (!is_string($receipt)||!preg_match('/^([a-f0-9]{64}):([1-9][0-9]{0,18})$/D',$receipt,$m)
            ||!hash_equals($hash,$m[1])||(string)$target!==$r['target_id']
            ||(string)(int)$m[2]!==$m[2]||($op!=='lead.schedule'&&$p['activity_id']!==(int)$m[2]))
            throw new DomainException('Scheduled activity receipt mismatch.');
        $row=$this->scheduled->read($org,$kind,$target,(int)$m[2]);
        $due=isset($p['due_at'])?(new DateTimeImmutable($p['due_at']))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s'):null;
        $matches=$row!==null&&(int)$row['id']===(int)$m[2]&&match($op){
            'lead.schedule'=>$row['activity_type']==='task'&&$row['title']===$p['title']
                &&($row['body']??'')===($p['body']??'')&&$row['due_at']===$due
                &&$row['completed_at']===null&&$row['cancelled_at']===null,
            'lead.reschedule'=>$row['due_at']===$due&&$row['completed_at']===null&&$row['cancelled_at']===null,
            'lead.complete'=>$row['completed_at']!==null&&$row['cancelled_at']===null,
            'lead.cancel','deal.cancel'=>$row['cancelled_at']!==null&&$row['completed_at']===null&&$row['cancellation_reason']===$p['reason'],
        };
        if (!$matches) throw new FederationOutcomeSourceNotReady('Scheduled activity state no longer observable.');
        return 'sales:'.$kind.':activity:'.$m[2].':'.$key;
    }

    private function offerProof(string $org,array $r,array $p,int $user,string $key): string
    {
        $op=self::OFFERS[$r['capability_id']];
        $hash=\Domains\Sales\Application\Support\SalesOfferSnapshot::digest(['actor'=>$user,'operation'=>$op,'input'=>$p]);
        $receipt=$this->nativeReceipts->find($org,'offer.'.$op,$key);
        if (!is_string($receipt)||!preg_match('/^([a-f0-9]{64}):([a-f0-9]{32})$/D',$receipt,$m)
            ||!hash_equals($hash,$m[1])||($op==='create'?(string)$p['deal_id']!==$r['target_id']:
                ($p['offer_id']!==$m[2]||$p['offer_id']!==$r['target_id'])))
            throw new DomainException('Native offer receipt mismatch.');
        $id=$m[2];$rev=$op==='create'?1:$p['expected_revision']+1;
        $row=$this->offers->get($org,$id);
        if ($row===null) throw new FederationOutcomeSourceNotReady('Native offer missing.');
        $state=\Domains\Sales\Application\Support\SalesOfferSnapshot::fromNative($row,$org,$id);
        if ($state['deal_id']!==$p['deal_id']||$state['revision']<$rev) throw new DomainException('Native offer relationship mismatch.');
        $history=array_values(array_filter($this->offers->history($org,$id),static fn(array $v):bool=>(int)$v['revision']===$rev));
        if (count($history)!==1) throw new FederationOutcomeSourceNotReady('Offer revision history not observable.');
        $h=$history[0];
        $kind=match($op){'create'=>'create','revise'=>$p['direction']==='customer'?'counteroffer':'revise','transition'=>$p['target_status'],'negotiation'=>$p['kind']};
        if ($h['kind']!==$kind||(int)$h['actor_id']!==$user||$h['correlation_id']!==$r['correlation_id']
            ||$h['evidence_reference']!==($p['evidence_reference']??null)||$h['note']!==($p['note']??null)
            ||($op==='transition'&&$h['to_status']!==$p['target_status']))
            throw new DomainException('Offer revision history differs from approved operation.');
        if (in_array($op,['create','revise'],true)) {
            $terms=array_values(array_filter($this->offers->termsHistory($org,$id),static fn(array $v):bool=>(int)$v['terms_version']===(int)$h['terms_version']));
            if (count($terms)!==1) throw new FederationOutcomeSourceNotReady('Immutable offer terms unavailable.');
            $t=$terms[0];$expected=\Domains\Sales\Domain\Policy\SalesOfferPolicy::terms($p);
            $lines=json_decode($t['lines_json'],true,32,JSON_THROW_ON_ERROR);
            $actual=['title'=>$t['title'],'currency'=>$t['currency'],'total'=>$t['total'],'valid_until'=>$t['valid_until'],'lines'=>$lines];
            if (!hash_equals($t['terms_hash'],\Domains\Sales\Application\Support\SalesOfferSnapshot::digest($actual)))
                throw new DomainException('Immutable offer terms hash mismatch.');
            foreach($actual['lines'] as &$line)unset($line['reference_snapshot']);unset($line);
            if (\Domains\Sales\Application\Support\SalesOfferSnapshot::digest($actual)!==\Domains\Sales\Application\Support\SalesOfferSnapshot::digest($expected)
                ||(int)$t['actor_id']!==$user||$t['direction']!==($p['direction']??'sales'))
                throw new DomainException('Offer terms differ from approved input.');
        }
        return 'sales:offer:'.$id.':'.$rev.':'.$key;
    }

    private function teamProof(string $org,array $p,int $user,string $key): string
    {
        $op=$p['operation'];ksort($p);
        $hash=hash('sha256',json_encode([$user,$p],JSON_THROW_ON_ERROR));
        $receipt=$this->nativeReceipts->find($org,'team.'.$op,$key);
        if ($op==='create') {
            if (!is_string($receipt)||!preg_match('/^([a-f0-9]{64}):([a-f0-9]{32})$/D',$receipt,$m)||!hash_equals($hash,$m[1]))
                throw new DomainException('Team creation receipt mismatch.');
            $id=$m[2];
        } else {
            if (!is_string($receipt)||!hash_equals('done:'.$hash,$receipt)) throw new DomainException('Team mutation receipt mismatch.');
            $id=$p['team_id'];
        }
        $row=$this->teams->team($org,$id);
        if ($row===null||$row['id']!==$id) throw new FederationOutcomeSourceNotReady('Native team unavailable.');
        $state=\App\Application\Sales\TeamSnapshot::fromRow($row);
        $matches=match($op){
            'create'=>$state['code']===$p['code']&&$state['name']===$p['name']&&$state['status']==='ACTIVE'&&$state['assignment_mode']==='MANUAL',
            'update'=>$state['name']===$p['name']&&$state['status']===$p['status']&&$state['assignment_mode']==='MANUAL',
            'member_set'=>count(array_filter($state['members'],static fn(array $m):bool=>$m['user_id']===$p['user_id']&&$m['role']===$p['role']&&$m['status']===$p['member_status']
                &&$m['assignment_enabled']===$p['assignment_enabled']&&$m['approval_enabled']===$p['approval_enabled']&&$m['assignment_weight']===$p['assignment_weight']))===1,
            default=>false,
        };
        if (!$matches) throw new FederationOutcomeSourceNotReady('Native team state changed.');
        return 'sales:team:'.$id.':'.$key;
    }
}
