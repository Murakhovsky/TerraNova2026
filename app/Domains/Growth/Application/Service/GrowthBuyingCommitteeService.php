<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domains\Growth\Application\Contract\GrowthBuyingCommitteeBoundary;
use Domains\Growth\Application\Contract\GrowthBuyingCommitteeRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthIntelligenceRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthMutationReceiptInterface;
use Domains\Growth\Automation\Event\GrowthEventType;
use Domains\Growth\Domain\BuyingCommitteeAnalyzer;
use Domains\Growth\Domain\BuyingRole;
use Domains\Growth\Domain\ContactSnapshot;
use Domains\Growth\Domain\GrowthContact;
use Domains\Growth\Domain\RelationshipStrength;
use InvalidArgumentException;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class GrowthBuyingCommitteeService implements GrowthBuyingCommitteeBoundary
{
    public function __construct(
        private GrowthBuyingCommitteeRepositoryInterface $committee,
        private GrowthIntelligenceRepositoryInterface $intelligence,
        private GrowthMutationReceiptInterface $receipts,
        private BuyingCommitteeAnalyzer $analyzer,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
        private AuditRepositoryInterface $audit,
    ) {}

    public function discoverContact(
        string $organizationId,int $actorId,string $correlationId,string $accountId,string $idempotencyKey,array $input
    ): array {
        $accountId=$this->bounded(trim($accountId),'accountId',80);
        if($this->intelligence->viewAccount($organizationId,$accountId)===null)throw new InvalidArgumentException('Growth account was not found.');
        $fullName=$this->required($input,'full_name',220);
        $identityType=strtolower($this->required($input,'identity_type',40));
        $identityValue=$this->required($input,'identity_value',500);
        $sourceReferences=$this->stringList($input['source_references']??null,'source_references');

        $normalized=$identityType==='email'?strtolower($identityValue):$identityValue;
        $contactId='GCNT-'.$this->stableId($organizationId.':contact:'.$identityType.':'.$normalized);
        $fingerprint=$this->fingerprint([
            'account_id'=>$accountId,'full_name'=>$fullName,'identity_type'=>$identityType,
            'identity_value'=>$normalized,'source_references'=>$sourceReferences,
        ]);

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$accountId,$idempotencyKey,$fullName,$identityType,
            $normalized,$sourceReferences,$contactId,$fingerprint
        ):array{
            if(!$this->receipts->claim($organizationId,'discover_contact',$idempotencyKey,$fingerprint)){
                return ($this->committee->viewContact($organizationId,$contactId)
                    ?? throw new InvalidArgumentException('Growth contact receipt exists but contact was not found.'))
                    + ['replayed'=>true];
            }

            $existing=$this->committee->findContactByIdentity($organizationId,$identityType,$normalized);
            if($existing!==null){
                $this->committee->linkContactToAccount($organizationId,$accountId,(string)$existing['contact_id'],$actorId);
                return $existing+['existing'=>true];
            }

            $contact=new GrowthContact(
                $contactId,OrganizationId::fromString($organizationId),$fullName,$identityType,$normalized,$sourceReferences,
            );
            $this->committee->createContact($contact,$actorId);
            $this->committee->linkContactToAccount($organizationId,$accountId,$contactId,$actorId);
            $this->publish(GrowthEventType::CONTACT_DISCOVERED,$organizationId,'growth_contact',$contactId,[
                'account_id'=>$accountId,'full_name'=>$fullName,'identity_type'=>$identityType,
                'source_references'=>$sourceReferences,
            ],$actorId,$correlationId);
            $this->appendAudit($organizationId,$actorId,$correlationId,'growth.contact.discover','growth_contact',$contactId,$idempotencyKey,[
                'account_id'=>$accountId,
            ]);
            return $this->committee->viewContact($organizationId,$contactId)
                ?? throw new InvalidArgumentException('Discovered Growth contact could not be read back.');
        });
    }

    public function captureContactSnapshot(
        string $organizationId,int $actorId,string $correlationId,string $accountId,string $contactId,string $idempotencyKey,array $input
    ): array {
        $accountId=$this->bounded(trim($accountId),'accountId',80);
        $contactId=$this->bounded(trim($contactId),'contactId',80);
        if($this->intelligence->viewAccount($organizationId,$accountId)===null)throw new InvalidArgumentException('Growth account was not found.');
        if($this->committee->viewContact($organizationId,$contactId)===null)throw new InvalidArgumentException('Growth contact was not found.');
        if(!$this->committee->isContactLinked($organizationId,$accountId,$contactId))throw new InvalidArgumentException('Growth contact is not linked to the account.');

        $title=$this->nullableString($input['title']??null,220);
        $department=$this->nullableString($input['department']??null,120);
        $seniority=$this->nullableString($input['seniority']??null,80);
        $roleValues=$this->stringList($input['buying_roles']??null,'buying_roles');
        $roles=BuyingRole::fromStrings($roleValues);
        $relationship=RelationshipStrength::tryFrom(strtolower($this->required($input,'relationship_strength',40)))
            ?? throw new InvalidArgumentException('Unknown Growth relationship strength.');
        $relationshipReason=$this->required($input,'relationship_reason',1000);
        $sources=$this->stringList($input['source_references']??null,'source_references');
        $observedAt=$this->date($input['observed_at']??null,'observed_at');
        $capturedAt=$this->now();
        $snapshotId='GCSN-'.$this->stableId($organizationId.':contact_snapshot:'.$idempotencyKey);
        $fingerprint=$this->fingerprint([
            'account_id'=>$accountId,'contact_id'=>$contactId,'title'=>$title,'department'=>$department,'seniority'=>$seniority,
            'buying_roles'=>array_map(static fn(BuyingRole $role):string=>$role->value,$roles),
            'relationship_strength'=>$relationship->value,'relationship_reason'=>$relationshipReason,
            'source_references'=>$sources,'observed_at'=>$observedAt->format(DATE_ATOM),
        ]);

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$accountId,$contactId,$idempotencyKey,$title,$department,$seniority,
            $roles,$relationship,$relationshipReason,$sources,$observedAt,$capturedAt,$snapshotId,$fingerprint
        ):array{
            if(!$this->receipts->claim($organizationId,'capture_contact_snapshot',$idempotencyKey,$fingerprint)){
                $snapshot=$this->committee->viewContactSnapshot($organizationId,$snapshotId)
                    ?? throw new InvalidArgumentException('Growth contact snapshot receipt exists but snapshot was not found.');
                return $snapshot->toArray()+['replayed'=>true];
            }
            $snapshot=new ContactSnapshot(
                $snapshotId,OrganizationId::fromString($organizationId),$accountId,$contactId,$title,$department,$seniority,
                $roles,$relationship,$relationshipReason,$sources,$observedAt,$capturedAt,
            );
            $this->committee->createContactSnapshot($snapshot,$actorId);
            $this->publish(GrowthEventType::CONTACT_SNAPSHOT_CAPTURED,$organizationId,'growth_contact',$contactId,[
                'account_id'=>$accountId,'snapshot_id'=>$snapshotId,
                'buying_roles'=>array_map(static fn(BuyingRole $role):string=>$role->value,$roles),
                'relationship_strength'=>$relationship->value,
            ],$actorId,$correlationId);
            $this->appendAudit($organizationId,$actorId,$correlationId,'growth.contact.snapshot','growth_contact',$contactId,$idempotencyKey,[
                'account_id'=>$accountId,'snapshot_id'=>$snapshotId,
            ]);
            return $snapshot->toArray();
        });
    }

    public function assessBuyingCommittee(
        string $organizationId,int $actorId,string $correlationId,string $accountId,string $idempotencyKey,array $requiredRoles
    ): array {
        $accountId=$this->bounded(trim($accountId),'accountId',80);
        if($this->intelligence->viewAccount($organizationId,$accountId)===null)throw new InvalidArgumentException('Growth account was not found.');
        $roleValues=$this->stringList($requiredRoles,'requiredRoles');
        $roles=BuyingRole::fromStrings($roleValues);
        $roleStrings=array_map(static fn(BuyingRole $role):string=>$role->value,$roles);
        sort($roleStrings,SORT_STRING);
        $fingerprint=$this->fingerprint(['account_id'=>$accountId,'required_roles'=>$roleStrings]);
        $assessmentId='GBCA-'.$this->stableId($organizationId.':committee:'.$idempotencyKey);

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$accountId,$idempotencyKey,$roles,$fingerprint,$assessmentId
        ):array{
            if(!$this->receipts->claim($organizationId,'assess_buying_committee',$idempotencyKey,$fingerprint)){
                return ($this->committee->viewAssessment($organizationId,$assessmentId)
                    ?? throw new InvalidArgumentException('Growth committee receipt exists but assessment was not found.'))
                    + ['replayed'=>true];
            }
            $snapshots=$this->committee->latestContactSnapshotsForAccount($organizationId,$accountId);
            $assessment=$this->analyzer->assess($accountId,$roles,$snapshots,$this->now());
            $this->committee->createAssessment($assessmentId,$organizationId,$assessment,$actorId);
            $this->publish(GrowthEventType::BUYING_COMMITTEE_ASSESSED,$organizationId,'growth_account',$accountId,[
                'assessment_id'=>$assessmentId,'coverage_score'=>$assessment->coverageScore,
                'relationship_score'=>$assessment->relationshipScore,
                'gaps'=>array_map(static fn(BuyingRole $role):string=>$role->value,$assessment->gaps),
            ],$actorId,$correlationId);
            $this->appendAudit($organizationId,$actorId,$correlationId,'growth.buying_committee.assess','growth_account',$accountId,$idempotencyKey,[
                'assessment_id'=>$assessmentId,'coverage_score'=>$assessment->coverageScore,
            ]);
            return $this->committee->viewAssessment($organizationId,$assessmentId)
                ?? throw new InvalidArgumentException('Growth committee assessment could not be read back.');
        });
    }

    public function buyingCommitteeBrief(string $organizationId,string $accountId): array
    {
        $accountId=$this->bounded(trim($accountId),'accountId',80);
        $account=$this->intelligence->viewAccount($organizationId,$accountId)
            ?? throw new InvalidArgumentException('Growth account was not found.');
        return [
            'account'=>$account,
            'contacts'=>$this->committee->accountContacts($organizationId,$accountId),
            'assessment'=>$this->committee->latestAssessment($organizationId,$accountId),
        ];
    }

    /** @return list<string> */
    private function stringList(mixed $value,string $field): array
    {
        if(!is_array($value)||!array_is_list($value)||$value===[])throw new InvalidArgumentException($field.' must be a non-empty list.');
        $out=[];
        foreach($value as $item){
            if(!is_string($item)||trim($item)==='')throw new InvalidArgumentException($field.' contains an invalid value.');
            $out[trim($item)]=true;
        }
        return array_keys($out);
    }

    /** @param array<string,mixed> $input */
    private function required(array $input,string $key,int $limit): string
    {
        return $this->bounded(trim((string)($input[$key]??'')),$key,$limit);
    }

    private function nullableString(mixed $value,int $limit): ?string
    {
        if($value===null)return null;
        if(!is_string($value))throw new InvalidArgumentException('Growth optional string is invalid.');
        $value=trim($value);
        if($value==='')return null;
        if(mb_strlen($value)>$limit)throw new InvalidArgumentException('Growth optional string is too long.');
        return $value;
    }

    private function bounded(string $value,string $field,int $limit): string
    {
        if($value===''||mb_strlen($value)>$limit)throw new InvalidArgumentException($field.' is invalid.');
        return $value;
    }

    private function date(mixed $value,string $field): DateTimeImmutable
    {
        if(!is_string($value)||trim($value)==='')throw new InvalidArgumentException($field.' is required.');
        try{return new DateTimeImmutable($value,new DateTimeZone('UTC'));}
        catch(\Throwable){throw new InvalidArgumentException($field.' is invalid.');}
    }

    /** @param array<string,mixed> $payload */
    private function publish(string $type,string $organizationId,string $aggregateType,string $aggregateId,array $payload,int $actorId,string $correlationId): void
    {
        $this->events->publish(new DomainEvent(
            bin2hex(random_bytes(16)),$organizationId,$type,$aggregateType,$aggregateId,$payload,
            new EventMetadata($correlationId,null,'USER',(string)$actorId),$this->now(),
        ));
    }

    /** @param array<string,mixed> $data */
    private function appendAudit(
        string $organizationId,int $actorId,string $correlationId,string $action,string $subjectType,string $subjectId,string $idempotencyKey,array $data=[]
    ): void {
        $this->audit->append(new AuditEntry(
            bin2hex(random_bytes(16)),$organizationId,'growth.buying_committee','USER',(string)$actorId,
            $subjectType,$subjectId,null,['action'=>$action,'idempotency_key_hash'=>hash('sha256',$idempotencyKey),'result'=>$data],
            $correlationId,$this->now(),
        ));
    }

    private function stableId(string $value): string { return strtoupper(substr(hash('sha256',$value),0,20)); }

    /** @param array<string,mixed> $value */
    private function fingerprint(array $value): string
    {
        $normalize=function(mixed $item)use(&$normalize):mixed{
            if(!is_array($item))return $item;
            if(array_is_list($item))return array_map($normalize,$item);
            ksort($item,SORT_STRING);
            foreach($item as $key=>$nested)$item[$key]=$normalize($nested);
            return $item;
        };
        return hash('sha256',(string)json_encode($normalize($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION));
    }

    private function now(): DateTimeImmutable { return new DateTimeImmutable('now',new DateTimeZone('UTC')); }
}
