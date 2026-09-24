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
        if(!in_array($identityType,['email','phone','linkedin','external_ref'],true)){
            throw new InvalidArgumentException('Unsupported Growth Contact identity type.');
        }
        if($identityType==='email'&&filter_var(trim($identityValue),FILTER_VALIDATE_EMAIL)===false){
            throw new InvalidArgumentException('Growth Contact email identity is invalid.');
        }
        if($identityType==='phone'&&!preg_match('/^\+[1-9][0-9]{7,14}$/',trim($identityValue))){
            throw new InvalidArgumentException('Growth Contact phone identity must use E.164 format.');
        }
        if($sourceReferences===[])throw new InvalidArgumentException('Growth Contact identity requires source references.');
        foreach($sourceReferences as $source){
            if(!is_string($source)||trim($source)==='')throw new InvalidArgumentException('Growth Contact source reference is invalid.');
        }
    }

    public function normalizedIdentityValue(): string
    {
        return match($this->identityType){
            'email'=>strtolower(trim($this->identityValue)),
            'phone'=>preg_replace('/[\s().-]+/','',trim($this->identityValue))?:trim($this->identityValue),
            default=>trim($this->identityValue),
        };
    }
}
