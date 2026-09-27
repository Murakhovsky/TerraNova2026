<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

use DomainException;

final class AutonomousOutreachDeferred extends DomainException
{
    public readonly string $eligibilityCode;
    /** @var array<string,mixed> */
    public readonly array $eligibility;

    /** @param array<string,mixed> $eligibility */
    public function __construct(string $eligibilityCode,string $message,array $eligibility=[])
    {
        $this->eligibilityCode=$eligibilityCode;
        $this->eligibility=array_replace($eligibility,[
            'can_propose'=>false,
            'code'=>$eligibilityCode,
            'reason'=>$message,
        ]);
        parent::__construct($message);
    }
}
