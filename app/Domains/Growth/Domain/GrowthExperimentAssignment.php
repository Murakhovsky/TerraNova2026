<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class GrowthExperimentAssignment
{
    /** @param array<string,mixed> $contextSnapshot */
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $experimentId,
        public string $candidateId,
        public string $variantKey,
        public GrowthExperimentAssignmentSource $source,
        public array $contextSnapshot,
        public DateTimeImmutable $assignedAt,
    ) {
        foreach(['id'=>$id,'experimentId'=>$experimentId,'candidateId'=>$candidateId,'variantKey'=>$variantKey] as $field=>$value){
            if(trim($value)==='')throw new InvalidArgumentException('Growth experiment assignment '.$field.' is required.');
        }
        if($contextSnapshot===[]||array_is_list($contextSnapshot)){
            throw new InvalidArgumentException('Growth experiment assignment context must be a non-empty object.');
        }
    }

    /** @return array<string,mixed> */
    public function toArray():array
    {
        return [
            'assignment_id'=>$this->id,
            'experiment_id'=>$this->experimentId,
            'candidate_id'=>$this->candidateId,
            'variant_key'=>$this->variantKey,
            'assignment_source'=>$this->source->value,
            'context_snapshot'=>$this->contextSnapshot,
            'assigned_at'=>$this->assignedAt->format(DATE_ATOM),
        ];
    }
}
