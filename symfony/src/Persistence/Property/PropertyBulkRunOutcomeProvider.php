<?php
declare(strict_types=1);

namespace App\Persistence\Property;

use App\Persistence\Federation\FederationOutcomeSourceNotReady;
use App\Persistence\Workflow\DoctrineWorkflowActionReceiptVerifier;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use DomainException;
use Domains\Property\Contract\PropertyReferencePort;
use Kernel\Tenant\Model\TenantContext;
use Platform\Orchestration\Goal\RunScopedGoalOutcomeEvidenceProviderInterface;
use Platform\Workflow\Model\WorkflowRunIdentity;

/** Native Property records + independent Action receipt, never a Workflow flag alone. */
final readonly class PropertyBulkRunOutcomeProvider implements RunScopedGoalOutcomeEvidenceProviderInterface
{
    public function __construct(
        private Connection $db,
        private PropertyReferencePort $properties,
        private DoctrineWorkflowActionReceiptVerifier $receipts,
    ) {}

    public function domain():string { return 'property'; }
    public function supports(string $criterionId):bool { return $criterionId==='property.bulk_assets_verified'; }

    public function observe(string $organizationId,string $criterionId,
        DateTimeImmutable $from,DateTimeImmutable $to):array {
        throw new FederationOutcomeSourceNotReady('Bulk outcome requires an immutable Run identity.');
    }

    public function observeRun(TenantContext $actor,string $runId,string $criterionId,
        DateTimeImmutable $from,DateTimeImmutable $to):array {
        if (!$this->supports($criterionId) || $to<=$from) throw new DomainException('Invalid bulk outcome scope.');
        $org=$actor->organizationId()->value();
        $row=$this->db->fetchAssociative(
            'SELECT w.input_digest,w.source_plan_id,w.state,s.step_id,s.state AS step_state,
                    s.result_reference,p.plan_json
             FROM cos_workflow_runs w
             INNER JOIN cos_workflow_steps s ON s.organization_id=w.organization_id AND s.run_id=w.run_id
             INNER JOIN cos_federation_plans p ON p.organization_id=w.organization_id AND p.plan_id=w.source_plan_id
             WHERE w.organization_id=:org AND w.run_id=:run
               AND w.definition_id=:definition AND s.capability_id=:cap',[
             'org'=>$org,'run'=>$runId,'definition'=>'federation.approved-plan',
             'cap'=>PropertyBulkIntakeService::CAPABILITY,
        ]);
        if (!$row || $row['state']!=='completed' || $row['step_state']!=='completed'
            || !is_string($row['result_reference']??null)) {
            throw new FederationOutcomeSourceNotReady('Property bulk Action/Workflow has no completed verified receipt.');
        }
        $attestation=$this->receipts->attestForAudit(
            new WorkflowRunIdentity($org,$runId),(string)$row['step_id'],
            (string)$row['result_reference'],(string)$row['input_digest']);
        if (!$attestation->verified) throw new DomainException('Bulk Action receipt is not independently verified.');
        $plan=json_decode((string)$row['plan_json'],true,32,JSON_THROW_ON_ERROR);
        $input=$plan['steps'][0]['input']??null;
        if (!is_array($input)||($input['target_type']??null)!=='property_bulk_batch'
            || !is_string($input['target_id']??null)) throw new DomainException('Bulk Plan input is invalid.');
        $batchId=$input['target_id'];
        $batch=$this->db->fetchAssociative(
            'SELECT digest,state FROM cos_property_bulk_batches
             WHERE organization_id=:org AND batch_id=:batch AND plan_id=:plan',[
             'org'=>$org,'batch'=>$batchId,'plan'=>$row['source_plan_id'],
        ]);
        if (!$batch || $batch['state']!=='verified'
            || ($input['parameters']['batch_digest']??null)!==$batch['digest']) {
            throw new FederationOutcomeSourceNotReady('Batch has no immutable verified native result.');
        }
        $items=$this->db->fetchAllAssociative(
            'SELECT item_index,fingerprint,native_asset_id,status,receipt_key,payload_json FROM cos_property_bulk_items
             WHERE organization_id=:org AND batch_id=:batch ORDER BY item_index',[
                'org'=>$org,'batch'=>$batchId,
            ]);
        if (count($items)<1||count($items)>10) throw new DomainException('Invalid bulk native evidence cardinality.');
        $evidence=[];
        $sealed=[];
        foreach($items as $offset=>$item) {
            $index=(int)$item['item_index'];
            if($index!==$offset) throw new DomainException('Non-contiguous Property batch receipt indices.');
            $source=json_decode((string)$item['payload_json'],true,32,JSON_THROW_ON_ERROR);
            if(!is_array($source)||array_is_list($source)) {
                throw new DomainException('Invalid immutable Property evidence source.');
            }
            $source=PropertyBulkIntakeService::canonicalize($source);
            $fingerprint=PropertyBulkIntakeService::fingerprint($source);
            if(!hash_equals($fingerprint,(string)$item['fingerprint'])) {
                throw new DomainException('Property evidence fingerprint drift.');
            }
            $claim=$this->db->fetchOne(
                'SELECT batch_id FROM cos_property_bulk_identity_claims
                 WHERE organization_id=:org AND fingerprint=:fp',
                ['org'=>$org,'fp'=>$fingerprint],
            );
            if($claim!==$batchId) throw new DomainException('Property evidence identity claim drift.');
            $receipt='cos-property-bulk:'.$batchId.':'.$index;
            if(($item['receipt_key']??null)!==$receipt) {
                throw new DomainException('Native Property receipt no longer matches the pinned item.');
            }
            $sealed[]=['index'=>$index,'fingerprint'=>$fingerprint,'input'=>$source];
            $assetId=$item['native_asset_id']??null;
            if ($item['status']!=='verified'||!is_string($assetId)||$assetId==='') {
                throw new FederationOutcomeSourceNotReady('Partial batch is not a successful Goal outcome.');
            }
            $native=$this->properties->getPropertyReference($org,$assetId);
            if (!is_array($native) || ($native['asset_id']??null)!==$assetId
                || ($native['type_code']??null)!==($source['type_code']??null)
                || mb_strtolower(trim((string)($native['formatted_address']??'')))
                    !== mb_strtolower(trim((string)$source['address']))) {
                throw new DomainException('Native Property record does not confirm batch evidence.');
            }
            $evidence[]='property:asset:'.$assetId;
        }
        $sealedDigest=hash('sha256',json_encode(
            $sealed,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        if(!hash_equals((string)$batch['digest'],$sealedDigest)) {
            throw new DomainException('Property native outcome no longer matches the approved batch.');
        }
        if (count(array_unique($evidence))!==count($items)) {
            throw new DomainException('Duplicate Asset references cannot satisfy a Goal.');
        }
        return [
            'value'=>count($items),'evidence'=>$evidence,
            'source'=>'property.native_assets.bulk_action.v1',
            'window_start'=>$from->format('Y-m-d\TH:i:s.uP'),
            'window_end'=>$to->format('Y-m-d\TH:i:s.uP'),
            'attribution'=>'run_linked_action','run_id'=>$runId,
        ];
    }
}
