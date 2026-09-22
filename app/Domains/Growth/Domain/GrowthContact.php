<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class GrowthContact
{
    /** @param list<string> $sourceReferences */
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $fullName,
        public string $identityType,
        public string $identityValue,
        public array $sourceReferences,
    ) {
        if(trim($id)===''||trim($fullName)===''||trim($identityType)===''||trim($identityValue)===''){
            throw new InvalidArgumentException('Growth Contact identity is incomplete.');
        }
        if(!in_array($identityType,['email','linkedin','external_ref'],true)){
            throw new InvalidArgumentException('Unsupported Growth Contact identity type.');
        }
        if($sourceReferences===[])throw new InvalidArgumentException('Growth Contact identity requires source references.');
        foreach($sourceReferences as $source){
            if(!is_string($source)||trim($source)==='')throw new InvalidArgumentException('Growth Contact source reference is invalid.');
        }
    }

    public function normalizedIdentityValue(): string
    {
        return $this->identityType==='email'
            ? strtolower(trim($this->identityValue))
            : trim($this->identityValue);
    }
}
