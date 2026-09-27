<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class BuyingCommitteeAssessment
{
    public const MODEL_VERSION='growth-buying-committee-v1';

    /**
     * @param list<BuyingRole> $requiredRoles
     * @param array<string,list<string>> $coverage
     * @param list<BuyingRole> $gaps
     * @param list<string> $championContactIds
     * @param list<string> $blockerContactIds
     * @param list<string> $weakRelationshipContactIds
     * @param list<string> $snapshotIds
     */
    public function __construct(
        public string $accountId,
        public array $requiredRoles,
        public array $coverage,
        public array $gaps,
        public array $championContactIds,
        public array $blockerContactIds,
        public array $weakRelationshipContactIds,
        public int $coverageScore,
        public int $relationshipScore,
        public array $snapshotIds,
        public DateTimeImmutable $assessedAt,
    ) {
        if(trim($accountId)===''||$requiredRoles===[]||$snapshotIds===[])throw new InvalidArgumentException('Growth BuyingCommitteeAssessment is incomplete.');
        if($coverageScore<0||$coverageScore>100||$relationshipScore<0||$relationshipScore>100){
            throw new InvalidArgumentException('Growth BuyingCommitteeAssessment scores must be between 0 and 100.');
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'account_id'=>$this->accountId,
            'required_roles'=>array_map(static fn(BuyingRole $role):string=>$role->value,$this->requiredRoles),
            'coverage'=>$this->coverage,
            'gaps'=>array_map(static fn(BuyingRole $role):string=>$role->value,$this->gaps),
            'champion_contact_ids'=>$this->championContactIds,
            'blocker_contact_ids'=>$this->blockerContactIds,
            'weak_relationship_contact_ids'=>$this->weakRelationshipContactIds,
            'coverage_score'=>$this->coverageScore,
            'relationship_score'=>$this->relationshipScore,
            'snapshot_ids'=>$this->snapshotIds,
            'model_version'=>self::MODEL_VERSION,
            'assessed_at'=>$this->assessedAt->format(DATE_ATOM),
        ];
    }
}
