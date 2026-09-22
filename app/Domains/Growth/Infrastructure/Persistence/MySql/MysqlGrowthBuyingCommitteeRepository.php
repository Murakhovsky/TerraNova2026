<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\Persistence\MySql;

use DateTimeImmutable;
use Domains\Growth\Application\Contract\GrowthBuyingCommitteeRepositoryInterface;
use Domains\Growth\Domain\BuyingCommitteeAssessment;
use Domains\Growth\Domain\BuyingRole;
use Domains\Growth\Domain\ContactSnapshot;
use Domains\Growth\Domain\GrowthContact;
use Domains\Growth\Domain\RelationshipStrength;
use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;
use PDO;

final readonly class MysqlGrowthBuyingCommitteeRepository implements GrowthBuyingCommitteeRepositoryInterface
{
    public function __construct(private PDO $connection) {}

    public function createContact(GrowthContact $contact,int $actorId): void
    {
        $this->execute(
            'INSERT INTO tn_growth_contacts
             (organization_id,contact_id,full_name,identity_type,identity_value,identity_hash,source_references_json,created_by,updated_by)
             VALUES(:organization_id,:contact_id,:full_name,:identity_type,:identity_value,:identity_hash,:source_references_json,:created_by,:updated_by)',
            [
                'organization_id'=>$contact->organizationId->value(),'contact_id'=>$contact->id,'full_name'=>$contact->fullName,
                'identity_type'=>$contact->identityType,'identity_value'=>$contact->normalizedIdentityValue(),
                'identity_hash'=>hash('sha256',$contact->normalizedIdentityValue()),'source_references_json'=>$this->encode($contact->sourceReferences),'created_by'=>$actorId,'updated_by'=>$actorId,
            ],
        );
    }

    public function findContactByIdentity(string $organizationId,string $identityType,string $identityValue): ?array
    {
        $row=$this->one(
            'SELECT organization_id,contact_id,full_name,identity_type,identity_value,identity_hash,source_references_json,
                    created_by,updated_by,created_at,updated_at
             FROM tn_growth_contacts
             WHERE organization_id=:organization_id AND identity_type=:identity_type AND identity_hash=:identity_hash LIMIT 1',
            ['organization_id'=>$organizationId,'identity_type'=>$identityType,'identity_hash'=>hash('sha256',$identityValue)],
        );
        return $this->hydrateContactRow($row);
    }

    public function viewContact(string $organizationId,string $contactId): ?array
    {
        $row=$this->one(
            'SELECT organization_id,contact_id,full_name,identity_type,identity_value,source_references_json,
                    created_by,updated_by,created_at,updated_at
             FROM tn_growth_contacts
             WHERE organization_id=:organization_id AND contact_id=:contact_id LIMIT 1',
            ['organization_id'=>$organizationId,'contact_id'=>$contactId],
        );
        return $this->hydrateContactRow($row);
    }

    public function linkContactToAccount(string $organizationId,string $accountId,string $contactId,int $actorId): void
    {
        $this->execute(
            'INSERT IGNORE INTO tn_growth_account_contacts
             (organization_id,account_id,contact_id,created_by)
             VALUES(:organization_id,:account_id,:contact_id,:created_by)',
            ['organization_id'=>$organizationId,'account_id'=>$accountId,'contact_id'=>$contactId,'created_by'=>$actorId],
        );
    }

    public function isContactLinked(string $organizationId,string $accountId,string $contactId): bool
    {
        return $this->scalar(
            'SELECT 1 FROM tn_growth_account_contacts
             WHERE organization_id=:organization_id AND account_id=:account_id AND contact_id=:contact_id LIMIT 1',
            ['organization_id'=>$organizationId,'account_id'=>$accountId,'contact_id'=>$contactId],
        )!==false;
    }

    public function createContactSnapshot(ContactSnapshot $snapshot,int $actorId): void
    {
        $this->execute(
            'INSERT INTO tn_growth_contact_snapshots
             (organization_id,snapshot_id,account_id,contact_id,title,department,seniority,buying_roles_json,
              relationship_strength,relationship_reason,source_references_json,observed_at,captured_at,created_by)
             VALUES(:organization_id,:snapshot_id,:account_id,:contact_id,:title,:department,:seniority,:buying_roles_json,
                    :relationship_strength,:relationship_reason,:source_references_json,:observed_at,:captured_at,:created_by)',
            [
                'organization_id'=>$snapshot->organizationId->value(),'snapshot_id'=>$snapshot->id,'account_id'=>$snapshot->accountId,
                'contact_id'=>$snapshot->contactId,'title'=>$snapshot->title,'department'=>$snapshot->department,'seniority'=>$snapshot->seniority,
                'buying_roles_json'=>$this->encode(array_map(static fn(BuyingRole $role):string=>$role->value,$snapshot->buyingRoles)),
                'relationship_strength'=>$snapshot->relationshipStrength->value,'relationship_reason'=>$snapshot->relationshipReason,
                'source_references_json'=>$this->encode($snapshot->sourceReferences),
                'observed_at'=>$snapshot->observedAt->format('Y-m-d H:i:s.u'),'captured_at'=>$snapshot->capturedAt->format('Y-m-d H:i:s.u'),
                'created_by'=>$actorId,
            ],
        );
    }

    public function viewContactSnapshot(string $organizationId,string $snapshotId): ?ContactSnapshot
    {
        $row=$this->one(
            'SELECT organization_id,snapshot_id,account_id,contact_id,title,department,seniority,buying_roles_json,
                    relationship_strength,relationship_reason,source_references_json,observed_at,captured_at
             FROM tn_growth_contact_snapshots
             WHERE organization_id=:organization_id AND snapshot_id=:snapshot_id LIMIT 1',
            ['organization_id'=>$organizationId,'snapshot_id'=>$snapshotId],
        );
        return $row===null?null:$this->hydrateSnapshot($row);
    }

    public function latestContactSnapshotsForAccount(string $organizationId,string $accountId): array
    {
        $statement=$this->connection->prepare(
            'SELECT s.organization_id,s.snapshot_id,s.account_id,s.contact_id,s.title,s.department,s.seniority,s.buying_roles_json,
                    s.relationship_strength,s.relationship_reason,s.source_references_json,s.observed_at,s.captured_at
             FROM tn_growth_contact_snapshots s
             INNER JOIN (
                 SELECT contact_id,MAX(captured_at) AS max_captured_at
                 FROM tn_growth_contact_snapshots
                 WHERE organization_id=:organization_id AND account_id=:account_id
                 GROUP BY contact_id
             ) latest
               ON latest.contact_id=s.contact_id AND latest.max_captured_at=s.captured_at
             WHERE s.organization_id=:organization_id2 AND s.account_id=:account_id2
             ORDER BY s.contact_id,s.snapshot_id DESC'
        );
        $statement->execute([
            'organization_id'=>$organizationId,'account_id'=>$accountId,
            'organization_id2'=>$organizationId,'account_id2'=>$accountId,
        ]);
        $snapshots=[];
        $seen=[];
        foreach($statement->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            $contactId=(string)$row['contact_id'];
            if(isset($seen[$contactId]))continue;
            $seen[$contactId]=true;
            $snapshots[]=$this->hydrateSnapshot($row);
        }
        return $snapshots;
    }

    public function accountContacts(string $organizationId,string $accountId): array
    {
        $statement=$this->connection->prepare(
            'SELECT c.organization_id,c.contact_id,c.full_name,c.identity_type,c.identity_value,c.identity_hash,c.source_references_json,
                    c.created_at,c.updated_at
             FROM tn_growth_account_contacts ac
             INNER JOIN tn_growth_contacts c
               ON c.organization_id=ac.organization_id AND c.contact_id=ac.contact_id
             WHERE ac.organization_id=:organization_id AND ac.account_id=:account_id
             ORDER BY c.full_name,c.contact_id'
        );
        $statement->execute(['organization_id'=>$organizationId,'account_id'=>$accountId]);
        $latest=[];
        foreach($this->latestContactSnapshotsForAccount($organizationId,$accountId) as $snapshot)$latest[$snapshot->contactId]=$snapshot;
        $rows=[];
        foreach($statement->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            $row=$this->hydrateContactRow($row)??[];
            $contactId=(string)($row['contact_id']??'');
            $row['latest_snapshot']=isset($latest[$contactId])?$latest[$contactId]->toArray():null;
            $rows[]=$row;
        }
        return $rows;
    }

    public function createAssessment(string $assessmentId,string $organizationId,BuyingCommitteeAssessment $assessment,int $actorId): void
    {
        $this->execute(
            'INSERT INTO tn_growth_buying_committee_assessments
             (organization_id,assessment_id,account_id,required_roles_json,coverage_json,gaps_json,champion_contact_ids_json,
              blocker_contact_ids_json,weak_relationship_contact_ids_json,coverage_score,relationship_score,
              snapshot_ids_json,model_version,assessed_at,created_by)
             VALUES(:organization_id,:assessment_id,:account_id,:required_roles_json,:coverage_json,:gaps_json,:champion_contact_ids_json,
                    :blocker_contact_ids_json,:weak_relationship_contact_ids_json,:coverage_score,:relationship_score,
                    :snapshot_ids_json,:model_version,:assessed_at,:created_by)',
            [
                'organization_id'=>$organizationId,'assessment_id'=>$assessmentId,'account_id'=>$assessment->accountId,
                'required_roles_json'=>$this->encode(array_map(static fn(BuyingRole $role):string=>$role->value,$assessment->requiredRoles)),
                'coverage_json'=>$this->encode($assessment->coverage),
                'gaps_json'=>$this->encode(array_map(static fn(BuyingRole $role):string=>$role->value,$assessment->gaps)),
                'champion_contact_ids_json'=>$this->encode($assessment->championContactIds),
                'blocker_contact_ids_json'=>$this->encode($assessment->blockerContactIds),
                'weak_relationship_contact_ids_json'=>$this->encode($assessment->weakRelationshipContactIds),
                'coverage_score'=>$assessment->coverageScore,'relationship_score'=>$assessment->relationshipScore,
                'snapshot_ids_json'=>$this->encode($assessment->snapshotIds),'model_version'=>BuyingCommitteeAssessment::MODEL_VERSION,
                'assessed_at'=>$assessment->assessedAt->format('Y-m-d H:i:s.u'),'created_by'=>$actorId,
            ],
        );
    }

    public function viewAssessment(string $organizationId,string $assessmentId): ?array
    {
        $row=$this->one(
            'SELECT organization_id,assessment_id,account_id,required_roles_json,coverage_json,gaps_json,champion_contact_ids_json,
                    blocker_contact_ids_json,weak_relationship_contact_ids_json,coverage_score,relationship_score,
                    snapshot_ids_json,model_version,assessed_at,created_by,created_at
             FROM tn_growth_buying_committee_assessments
             WHERE organization_id=:organization_id AND assessment_id=:assessment_id LIMIT 1',
            ['organization_id'=>$organizationId,'assessment_id'=>$assessmentId],
        );
        return $this->hydrateAssessment($row);
    }

    public function latestAssessment(string $organizationId,string $accountId): ?array
    {
        $row=$this->one(
            'SELECT organization_id,assessment_id,account_id,required_roles_json,coverage_json,gaps_json,champion_contact_ids_json,
                    blocker_contact_ids_json,weak_relationship_contact_ids_json,coverage_score,relationship_score,
                    snapshot_ids_json,model_version,assessed_at,created_by,created_at
             FROM tn_growth_buying_committee_assessments
             WHERE organization_id=:organization_id AND account_id=:account_id
             ORDER BY assessed_at DESC,assessment_id DESC LIMIT 1',
            ['organization_id'=>$organizationId,'account_id'=>$accountId],
        );
        return $this->hydrateAssessment($row);
    }

    /** @param array<string,mixed> $row */
    private function hydrateSnapshot(array $row): ContactSnapshot
    {
        $relationship=RelationshipStrength::tryFrom((string)$row['relationship_strength'])
            ?? throw new InvalidArgumentException('Stored Growth relationship strength is invalid.');
        return new ContactSnapshot(
            (string)$row['snapshot_id'],OrganizationId::fromString((string)$row['organization_id']),
            (string)$row['account_id'],(string)$row['contact_id'],$row['title']===null?null:(string)$row['title'],
            $row['department']===null?null:(string)$row['department'],$row['seniority']===null?null:(string)$row['seniority'],
            BuyingRole::fromStrings($this->decodeList((string)$row['buying_roles_json'])),$relationship,
            (string)$row['relationship_reason'],$this->decodeList((string)$row['source_references_json']),
            new DateTimeImmutable((string)$row['observed_at']),new DateTimeImmutable((string)$row['captured_at']),
        );
    }

    /** @param array<string,mixed>|null $row @return array<string,mixed>|null */
    private function hydrateContactRow(?array $row): ?array
    {
        if($row===null)return null;
        $row['source_references']=$this->decodeList((string)$row['source_references_json']);
        unset($row['source_references_json']);
        return $row;
    }

    /** @param array<string,mixed>|null $row @return array<string,mixed>|null */
    private function hydrateAssessment(?array $row): ?array
    {
        if($row===null)return null;
        foreach([
            'required_roles_json'=>'required_roles','coverage_json'=>'coverage','gaps_json'=>'gaps',
            'champion_contact_ids_json'=>'champion_contact_ids','blocker_contact_ids_json'=>'blocker_contact_ids',
            'weak_relationship_contact_ids_json'=>'weak_relationship_contact_ids','snapshot_ids_json'=>'snapshot_ids',
        ] as $column=>$target){
            $decoded=json_decode((string)$row[$column],true,512,JSON_THROW_ON_ERROR);
            if(!is_array($decoded))throw new InvalidArgumentException('Stored Growth committee JSON is invalid.');
            $row[$target]=$decoded;
            unset($row[$column]);
        }
        $row['coverage_score']=(int)$row['coverage_score'];
        $row['relationship_score']=(int)$row['relationship_score'];
        return $row;
    }

    private function execute(string $sql,array $params): void
    {
        $statement=$this->connection->prepare($sql);
        $statement->execute($params);
    }

    private function one(string $sql,array $params): ?array
    {
        $statement=$this->connection->prepare($sql);
        $statement->execute($params);
        $row=$statement->fetch(PDO::FETCH_ASSOC);
        return $row===false?null:$row;
    }

    private function scalar(string $sql,array $params): string|int|false
    {
        $statement=$this->connection->prepare($sql);
        $statement->execute($params);
        return $statement->fetchColumn();
    }

    private function encode(array $value): string
    {
        return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @return list<string> */
    private function decodeList(string $json): array
    {
        $value=json_decode($json,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($value)||!array_is_list($value))throw new InvalidArgumentException('Stored Growth JSON list is invalid.');
        return array_values(array_map('strval',$value));
    }
}
