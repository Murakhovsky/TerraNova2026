<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class ContactSnapshot
{
    /**
     * @param list<BuyingRole> $buyingRoles
     * @param list<string> $sourceReferences
     */
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $accountId,
        public string $contactId,
        public ?string $title,
        public ?string $department,
        public ?string $seniority,
        public array $buyingRoles,
        public RelationshipStrength $relationshipStrength,
        public string $relationshipReason,
        public array $sourceReferences,
        public DateTimeImmutable $observedAt,
        public DateTimeImmutable $capturedAt,
    ) {
        if(trim($id)===''||trim($accountId)===''||trim($contactId)===''){
            throw new InvalidArgumentException('Growth ContactSnapshot identity is required.');
        }
        if($title!==null&&trim($title)==='')throw new InvalidArgumentException('Growth ContactSnapshot title must be null or non-empty.');
        if($buyingRoles===[])throw new InvalidArgumentException('Growth ContactSnapshot requires at least one buying role.');
        foreach($buyingRoles as $role)if(!$role instanceof BuyingRole)throw new InvalidArgumentException('Growth ContactSnapshot buying role is invalid.');
        if(trim($relationshipReason)==='')throw new InvalidArgumentException('Growth ContactSnapshot requires relationship reason.');
        if($sourceReferences===[])throw new InvalidArgumentException('Growth ContactSnapshot requires source references.');
        foreach($sourceReferences as $source)if(!is_string($source)||trim($source)==='')throw new InvalidArgumentException('Growth ContactSnapshot source reference is invalid.');
        if($capturedAt<$observedAt)throw new InvalidArgumentException('Growth ContactSnapshot cannot be captured before observed time.');
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'snapshot_id'=>$this->id,
            'account_id'=>$this->accountId,
            'contact_id'=>$this->contactId,
            'title'=>$this->title,
            'department'=>$this->department,
            'seniority'=>$this->seniority,
            'buying_roles'=>array_map(static fn(BuyingRole $role):string=>$role->value,$this->buyingRoles),
            'relationship_strength'=>$this->relationshipStrength->value,
            'relationship_reason'=>$this->relationshipReason,
            'source_references'=>$this->sourceReferences,
            'observed_at'=>$this->observedAt->format(DATE_ATOM),
            'captured_at'=>$this->capturedAt->format(DATE_ATOM),
        ];
    }
}
