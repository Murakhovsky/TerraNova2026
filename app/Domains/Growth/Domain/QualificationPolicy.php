<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

use DomainException;
use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final class QualificationPolicy
{
    private QualificationPolicyStatus $status;

    private function __construct(
        public readonly string $id,
        public readonly OrganizationId $organizationId,
        public readonly int $revision,
        public readonly string $name,
        public readonly QualificationPolicyCriteria $criteria,
        QualificationPolicyStatus $status,
    ) {
        if(trim($id)===''||trim($name)===''||$revision<1)throw new InvalidArgumentException('Invalid Growth QualificationPolicy.');
        $this->status=$status;
    }

    public static function draft(
        string $id,OrganizationId $organizationId,string $name,QualificationPolicyCriteria $criteria,int $revision=1
    ): self {
        return new self($id,$organizationId,$revision,$name,$criteria,QualificationPolicyStatus::Draft);
    }

    public static function restore(
        string $id,OrganizationId $organizationId,int $revision,string $name,
        QualificationPolicyCriteria $criteria,QualificationPolicyStatus $status
    ): self {
        return new self($id,$organizationId,$revision,$name,$criteria,$status);
    }

    public function status(): QualificationPolicyStatus { return $this->status; }

    public function revise(string $name,QualificationPolicyCriteria $criteria): self
    {
        if($this->status===QualificationPolicyStatus::Draft){
            throw new DomainException('Draft Growth qualification policy must be edited before activation, not revised.');
        }
        return self::draft($this->id,$this->organizationId,$name,$criteria,$this->revision+1);
    }

    public function activate(): void
    {
        if($this->status!==QualificationPolicyStatus::Draft)throw new DomainException('Only draft Growth qualification policy can be activated.');
        $this->status=QualificationPolicyStatus::Active;
    }

    public function archive(): void
    {
        if($this->status!==QualificationPolicyStatus::Active)throw new DomainException('Only active Growth qualification policy can be archived.');
        $this->status=QualificationPolicyStatus::Archived;
    }
}
