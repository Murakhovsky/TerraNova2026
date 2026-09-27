<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class GrowthAccount
{
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $name,
        public string $canonicalDomain,
    ) {
        if(trim($id)===''||trim($name)===''||trim($canonicalDomain)==='')throw new InvalidArgumentException('Growth Account id, name and canonical domain are required.');
        if(str_contains($canonicalDomain,'://')||str_contains($canonicalDomain,'/'))throw new InvalidArgumentException('Growth Account canonical domain must not contain URL syntax.');
    }
}
