<?php
declare(strict_types=1);

namespace Domains\Property\Model;

final readonly class PropertyIdentityResolutionCandidate
{
    public function __construct(
        public PropertyIdentity $identity,
        public PropertyIdentitySignals $signals,
    ) {}
}
