<?php
declare(strict_types=1);
namespace Domains\Finance\Application\Service;
use DomainException;
use Domains\Finance\Application\Contract\FinancePaidExposureClaimStore;
use Domains\Finance\Application\Contract\FinancePaidSettlementEvidencePort;
use Domains\Finance\Application\Contract\FinancePaidSettlementSourcePort;
use Domains\Finance\Application\Contract\FinancePaidSettlementReviewStore;
use Domains\Finance\Application\Contract\FinanceBudgetRepositoryInterface;
use Domains\Finance\Domain\FinancePaidSettlementPolicy;
use Domains\Finance\Domain\BudgetControlPolicy;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;

/** Human document review is evidence, not permission to spend. Only a separate approved Workflow settles. */
final readonly class FinancePaidSettlementReviewService implements FinancePaidSettlementEvidencePort
{
    public function __construct(private FinancePaidExposureClaimStore $claims,private FinanceBudgetRepositoryInterface $budgets,
        private FinancePaidSettlementSourcePort $source,private FinancePaidSettlementReviewStore $reviews,
        private FinancePaidSettlementPolicy $policy,private ActiveModuleResolver $modules){}
    public function preview(TenantContext $actor,string $reservationId,string $documentRef,string $snapshotRef):array
    {
        $org=$this->guard($actor);BudgetControlPolicy::id($reservationId,'finr-');
        $claim=$this->claims->exposure($org,$reservationId)??throw new DomainException('Native exposure required.');
        $facts=$this->source->marketing($actor,$claim['source_ref'],$snapshotRef);
        $doc=$this->source->document($actor,$documentRef);
        $budget=$this->budgets->envelope($org,$claim['envelope_id'])??throw new DomainException('Native envelope required.');
        if($budget['reserved_minor']<$claim['amount_minor']&&$budget['committed_minor']===0)throw new DomainException('Finance exposure is not held.');
        return ['claim'=>['organization_id'=>$org,...$claim],'facts'=>$facts,'file'=>$doc,'expected_revision'=>$budget['revision']];
    }
    /** Only an authenticated interactive human entry point may call this method. */
    public function review(TenantContext $actor,string $reservationId,string $documentRef,string $snapshotRef,
        string $documentHash,string $sourceHash,int $sourceRevision,string $providerDocumentId,int $minor):array
    {
        $org=$this->guard($actor);$user=$actor->userId()->value();$p=$this->preview($actor,$reservationId,$documentRef,$snapshotRef);
        $f=$p['facts'];$file=$p['file'];$o=$f['observation'];
        if(!preg_match('/^[1-9][0-9]{0,18}$/D',$user)||in_array($user,[$p['claim']['actor_id'],$file['uploader_id'],$file['creator_id']],true)
            ||$file['sha256']!==$documentHash||$o['source_hash']!==$sourceHash||$o['source_revision']!==$sourceRevision)
            throw new DomainException('Independent reviewer and current exact document/source pins required.');
        $ref='finv-'.substr(hash('sha256',json_encode([$org,$reservationId,$documentRef,$file['version_ref'],$snapshotRef,$sourceRevision],JSON_THROW_ON_ERROR)),0,24);
        $doc=[...$o,'provider'=>'meta','document_state'=>'INDEPENDENTLY_REVIEWED_FINAL','allocation_scope'=>'EXACT_CAMPAIGN_NET_MEDIA_SPEND',
            'plan_hash'=>$f['plan']['plan_hash'],'pause_action_id'=>$f['terminal']['pause_action_id'],'document_sha256'=>$documentHash,
            'document_ref'=>$documentRef,'provider_document_id'=>$providerDocumentId,'review_ref'=>$ref,'reviewer_id'=>$user,'settlement_minor'=>$minor];
        $candidate=$this->policy->prepare($org,$p['claim'],$f['plan'],$f['terminal'],$o,$doc);
        return $this->reviews->record($org,['document'=>$doc,'document_version_ref'=>$file['version_ref'],'candidate'=>$candidate]);
    }
    public function read(TenantContext $actor,string $reservationId,string $documentRef,string $snapshotRef):array
    {
        $org=$this->guard($actor);$p=$this->preview($actor,$reservationId,$documentRef,$snapshotRef);
        $f=$p['facts'];$o=$f['observation'];$file=$p['file'];
        $review=$this->reviews->find($org,$reservationId,$documentRef,$file['version_ref'],$snapshotRef,$o['source_revision'])
            ??throw new DomainException('No independent review of this exact document and source revision.');
        $doc=$review['document'];
        if($doc['document_sha256']!==$file['sha256']||in_array($doc['reviewer_id'],[$file['uploader_id'],$file['creator_id'],$p['claim']['actor_id']],true))
            throw new DomainException('Reviewed document provenance changed.');
        $candidate=$this->policy->prepare($org,$p['claim'],$f['plan'],$f['terminal'],$o,$doc);
        if($candidate['candidate_hash']!==$review['candidate']['candidate_hash'])throw new DomainException('Review candidate changed.');
        return [...$f,'document'=>$doc,'candidate'=>$candidate,'expected_revision'=>$p['expected_revision']];
    }
    private function guard(TenantContext $actor):string
    {
        $org=$actor->organizationId()->value();
        if(!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE)
            ||!$this->modules->isEnabled($org,'finance')||!$this->modules->isEnabled($org,'marketing')||!$this->modules->isEnabled($org,'documents'))
            throw new DomainException('Active Finance, Marketing and Documents tenant authority required.');
        return $org;
    }
}
