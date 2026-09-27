<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

use DomainException;
use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final class IcpProfile
{
    private IcpProfileStatus $status;

    private function __construct(
        public readonly string $id,
        public readonly OrganizationId $organizationId,
        public readonly int $revision,
        public readonly string $name,
        public readonly IcpCriteria $criteria,
        IcpProfileStatus $status,
    ) {
        if(trim($id)===''||trim($name)===''||$revision<1)throw new InvalidArgumentException('Invalid Growth ICP profile.');
        $this->status=$status;
    }

    public static function draft(string $id,OrganizationId $organizationId,string $name,IcpCriteria $criteria,int $revision=1): self
    {
        return new self($id,$organizationId,$revision,$name,$criteria,IcpProfileStatus::Draft);
    }

    public static function restore(string $id,OrganizationId $organizationId,int $revision,string $name,IcpCriteria $criteria,IcpProfileStatus $status): self
    {
        return new self($id,$organizationId,$revision,$name,$criteria,$status);
    }

    public function status(): IcpProfileStatus { return $this->status; }

    public function revise(string $name,IcpCriteria $criteria): self
    {
        if($this->status!==IcpProfileStatus::Active){
            throw new DomainException('Only active ICP profile can be revised into a new draft revision.');
        }
        return self::draft($this->id,$this->organizationId,$name,$criteria,$this->revision+1);
    }

    public function activate(): void
    {
        if($this->status!==IcpProfileStatus::Draft)throw new DomainException('Only draft ICP profile can be activated.');
        $this->status=IcpProfileStatus::Active;
    }

    public function archive(): void
    {
        if($this->status!==IcpProfileStatus::Active)throw new DomainException('Only active ICP profile can be archived.');
        $this->status=IcpProfileStatus::Archived;
    }
}
